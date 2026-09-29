<?php

namespace Modules\Donations\Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Services\ContributionDueService;
use Modules\Donations\Services\ContributionPlanService;
use Modules\Donations\Services\DonationLedgerService;
use Modules\Donations\Services\DonationProjectService;
use Modules\Donations\Services\ProjectInstallmentDueService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * Realistic stewardship demo: funds, plans, projects, dues, installments, and payments.
 *
 * Run: php artisan donations:seed-stewardship-demo --tenant=1
 */
class StewardshipDemoSeeder extends Seeder
{
    public const MARKER = 'stewardship_demo_v1';

    public const PLAN_CODE_MONTHLY = 'DEMO_MONTHLY_PARISH';

    public function run(): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found. Set STEWARDSHIP_DEMO_TENANT_ID.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor = $this->resolveActor($tenantId);
        if (! $actor) {
            $this->command?->error('No user found for stewardship demo seeding.');

            return;
        }

        Auth::login($actor);
        app()->instance(TenantContext::class, new TenantContext(
            (int) $actor->id,
            $actor->tenant_id ? (int) $actor->tenant_id : $tenantId,
            $tenantId,
            null,
            null,
        ));

        if (ContributionPlan::forTenant($tenantId)->where('code', self::PLAN_CODE_MONTHLY)->exists()) {
            $this->command?->info('Stewardship demo already present. Running verification.');
            $this->reportVerification($tenantId);

            return;
        }

        $families = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('bcc_id')
            ->orderBy('family_code')
            ->get(['id', 'family_name', 'head_of_family', 'bcc_id', 'family_code']);

        if ($families->isEmpty()) {
            $this->command?->error('No active BCC-assigned families found.');

            return;
        }

        $this->command?->info(sprintf('Seeding stewardship demo for %d families (tenant #%d).', $families->count(), $tenantId));

        $fund = $this->ensureFund($tenantId, (int) $actor->id);
        $planService = app(ContributionPlanService::class);
        $dueService = app(ContributionDueService::class);
        $projectService = app(DonationProjectService::class);
        $installmentService = app(ProjectInstallmentDueService::class);
        $ledger = app(DonationLedgerService::class);

        $scheduleStart = Carbon::parse('2025-04-01');
        $today = DonationBusinessDate::today($tenantId);

        $monthlyPlan = $planService->create($tenantId, (int) $actor->id, [
            'fund_id' => $fund->id,
            'name' => 'Monthly Parish Family Contribution',
            'code' => self::PLAN_CODE_MONTHLY,
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 500.00,
            'start_date' => $scheduleStart->toDateString(),
            'end_date' => null,
            'grace_days' => 7,
            'auto_generate' => true,
            'description' => self::MARKER.' — regular monthly family stewardship.',
            'status' => 'active',
        ]);

        $annualPlan = $planService->create($tenantId, (int) $actor->id, [
            'fund_id' => $fund->id,
            'name' => 'Annual Parish Dues',
            'code' => 'DEMO_ANNUAL_PARISH',
            'plan_type' => 'uniform',
            'frequency' => 'yearly',
            'default_amount' => 6000.00,
            'start_date' => $scheduleStart->toDateString(),
            'grace_days' => 14,
            'auto_generate' => true,
            'description' => self::MARKER.' — yearly family commitment.',
            'status' => 'active',
        ]);

        $festivalPlan = $planService->create($tenantId, (int) $actor->id, [
            'fund_id' => $fund->id,
            'name' => 'Parish Feast Offering 2026',
            'code' => 'DEMO_FEAST_2026',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 1000.00,
            'start_date' => Carbon::parse($today)->startOfMonth()->toDateString(),
            'end_date' => Carbon::parse($today)->endOfMonth()->toDateString(),
            'grace_days' => 0,
            'auto_generate' => true,
            'description' => self::MARKER.' — one-time feast participation.',
            'status' => 'active',
        ]);

        $individualPlan = $planService->create($tenantId, (int) $actor->id, [
            'fund_id' => $fund->id,
            'name' => 'Family Support Pledge',
            'code' => 'DEMO_FAMILY_PLEDGE',
            'plan_type' => 'individual',
            'frequency' => 'quarterly',
            'default_amount' => 1.00,
            'start_date' => $scheduleStart->toDateString(),
            'grace_days' => 10,
            'auto_generate' => true,
            'description' => self::MARKER.' — varied quarterly pledges.',
            'status' => 'active',
        ]);

        $this->syncIndividualAssignments($planService, $tenantId, (int) $actor->id, $individualPlan, $families, $scheduleStart->toDateString());

        foreach ([$monthlyPlan, $annualPlan, $festivalPlan, $individualPlan] as $plan) {
            $result = $dueService->generateSchedule($tenantId, (int) $actor->id, $plan->fresh(), null, true);
            $this->command?->info(sprintf(
                'Plan %s: generated %d dues (%d families × %d periods).',
                $plan->code,
                $result['generated_count'] ?? 0,
                $result['family_count'] ?? 0,
                $result['period_count'] ?? 0
            ));
        }

        $projects = $this->createProjects($projectService, $tenantId, (int) $actor->id, $fund->id, $scheduleStart);
        foreach ($projects as $project) {
            $installmentService->generateForProject($tenantId, (int) $actor->id, $project->fresh());
            $this->command?->info(sprintf('Project %s installments generated.', $project->code));
        }

        $this->applyDemoPayments($ledger, $tenantId, (int) $actor->id, $families, $monthlyPlan->id, $projects, $today);

        $this->command?->info('Stewardship demo seeding complete.');
        $this->reportVerification($tenantId);
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = env('STEWARDSHIP_DEMO_TENANT_ID');
        if ($tenantId) {
            return Tenant::query()->find($tenantId);
        }

        foreach (['Sacred Heart Church', 'Sacred Heart Parish'] as $name) {
            $tenant = Tenant::query()->where('name', $name)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()->orderBy('id')->first();
    }

    private function resolveActor(int $tenantId): ?User
    {
        return User::query()->where('tenant_id', $tenantId)->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();
    }

    private function ensureFund(int $tenantId, int $userId): Fund
    {
        return Fund::forTenant($tenantId)->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO_STEWARDSHIP'],
            [
                'name' => 'Parish Stewardship Fund',
                'description' => self::MARKER,
                'is_tax_deductible' => true,
                'status' => 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );
    }

    /**
     * @return list<DonationProject>
     */
    private function createProjects(
        DonationProjectService $projectService,
        int $tenantId,
        int $userId,
        string $fundId,
        Carbon $start,
    ): array {
        $definitions = [
            ['code' => 'DEMO_CHURCH_BUILD', 'name' => 'Church Construction', 'target' => 25000, 'installments' => 4, 'mode' => 'uniform'],
            ['code' => 'DEMO_AUDITORIUM', 'name' => 'Auditorium Construction', 'target' => 15000, 'installments' => 3, 'mode' => 'uniform'],
            ['code' => 'DEMO_SCHOOL_ADMIN', 'name' => 'School Administration', 'target' => 8000, 'installments' => 2, 'mode' => 'uniform'],
            ['code' => 'DEMO_EDU_SUPPORT', 'name' => 'Educational Support', 'target' => 5000, 'installments' => 2, 'mode' => 'uniform_with_exceptions'],
            ['code' => 'DEMO_MEDICAL', 'name' => 'Medical Support', 'target' => 4000, 'installments' => 2, 'mode' => 'uniform'],
            ['code' => 'DEMO_PARISH_DEV', 'name' => 'Parish Development', 'target' => 12000, 'installments' => 3, 'mode' => 'uniform'],
            ['code' => 'DEMO_WELFARE', 'name' => 'Poor & Welfare Support', 'target' => 3000, 'installments' => 1, 'mode' => 'uniform'],
            ['code' => 'DEMO_YOUTH', 'name' => 'Youth Ministry', 'target' => 2500, 'installments' => 2, 'mode' => 'uniform'],
        ];

        $created = [];
        foreach ($definitions as $row) {
            $created[] = $projectService->create($tenantId, $userId, [
                'fund_id' => $fundId,
                'name' => $row['name'],
                'code' => $row['code'],
                'assignment_mode' => $row['mode'],
                'default_family_target' => $row['target'],
                'target_amount' => $row['target'] * 500,
                'start_date' => $start->toDateString(),
                'installment_count' => $row['installments'],
                'installment_frequency' => 'quarterly',
                'auto_generate_installments' => false,
                'status' => 'active',
                'description' => self::MARKER,
            ]);
        }

        return $created;
    }

    /**
     * @param  Collection<int, Family>  $families
     */
    private function syncIndividualAssignments(
        ContributionPlanService $planService,
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        Collection $families,
        string $effectiveFrom,
    ): void {
        foreach ($families->chunk(250) as $chunk) {
            $assignments = [];
            foreach ($chunk as $family) {
                $amount = 750 + (hexdec(substr(md5((string) $family->id), 0, 4)) % 1250);
                $assignments[] = [
                    'family_id' => $family->id,
                    'amount' => $amount,
                    'effective_from' => $effectiveFrom,
                    'status' => 'active',
                ];
            }
            $planService->syncAssignments($tenantId, $userId, $plan, $assignments);
        }
    }

    /**
     * @param  list<DonationProject>  $projects
     */
    private function applyDemoPayments(
        DonationLedgerService $ledger,
        int $tenantId,
        int $userId,
        Collection $families,
        string $monthlyPlanId,
        array $projects,
        string $businessToday,
    ): void {
        $paymentsCreated = 0;
        $churchProject = collect($projects)->firstWhere('code', 'DEMO_CHURCH_BUILD');
        $welfareProject = collect($projects)->firstWhere('code', 'DEMO_WELFARE');

        foreach ($families as $family) {
            $bucket = hexdec(substr(md5((string) $family->id), 0, 2)) % 10;
            $payer = $family->head_of_family ?: $family->family_name;

            if ($bucket <= 3) {
                $paymentsCreated += $this->payMonthlyDues($ledger, $tenantId, $userId, $family, $monthlyPlanId, $payer, true);
            } elseif ($bucket <= 5) {
                $paymentsCreated += $this->payMonthlyDues($ledger, $tenantId, $userId, $family, $monthlyPlanId, $payer, false);
            }

            if ($bucket >= 4 && $bucket <= 7 && $churchProject) {
                $paymentsCreated += $this->payFirstProjectInstallment(
                    $ledger,
                    $tenantId,
                    $userId,
                    $family,
                    $churchProject->id,
                    $payer,
                    $bucket <= 5
                );
            }

            if ($bucket === 8 && $welfareProject) {
                $paymentsCreated += $this->payFirstProjectInstallment(
                    $ledger,
                    $tenantId,
                    $userId,
                    $family,
                    $welfareProject->id,
                    $payer,
                    true
                );
            }

            if ($bucket === 9) {
                $paymentsCreated += $this->payAnnualDue($ledger, $tenantId, $userId, $family, $payer);
            }
        }

        $this->command?->info(sprintf('Recorded %d stewardship demo payments.', $paymentsCreated));
    }

    private function payMonthlyDues(
        DonationLedgerService $ledger,
        int $tenantId,
        int $userId,
        Family $family,
        string $planId,
        string $payer,
        bool $fullHistory,
    ): int {
        $query = ContributionDue::forTenant($tenantId)
            ->where('family_id', $family->id)
            ->where('plan_id', $planId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->orderBy('due_date');

        $dues = $fullHistory ? $query->limit(3)->get() : $query->limit(1)->get();
        $count = 0;

        foreach ($dues as $due) {
            $outstanding = ContributionBalance::outstandingString($due);
            if (! MoneyMath::isPositive($outstanding)) {
                continue;
            }

            $payAmount = $fullHistory
                ? $outstanding
                : MoneyMath::normalize(min(MoneyMath::toApiNumber($outstanding), 250));

            if (! MoneyMath::isPositive($payAmount)) {
                continue;
            }

            $key = 'demo_m_'.$family->id.'_'.$due->id;
            if ($this->paymentExists($tenantId, $key)) {
                continue;
            }

            $ledger->createPayment($tenantId, $userId, [
                'family_id' => $family->id,
                'payer_name' => $payer,
                'payment_date' => $due->due_date?->toDateString() ?? now()->toDateString(),
                'amount' => $payAmount,
                'method' => $count % 2 === 0 ? 'cash' : 'bank_transfer',
                'source_type' => 'contribution',
                'idempotency_key' => $key,
                'notes' => self::MARKER,
                'allocations' => [
                    [
                        'allocatable_type' => 'due',
                        'allocatable_id' => $due->id,
                        'amount' => $payAmount,
                    ],
                ],
            ]);
            $count++;
        }

        return $count;
    }

    private function payFirstProjectInstallment(
        DonationLedgerService $ledger,
        int $tenantId,
        int $userId,
        Family $family,
        string $projectId,
        string $payer,
        bool $fullAmount,
    ): int {
        $installment = ProjectInstallmentDue::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $family->id)
            ->orderBy('installment_number')
            ->first();

        if (! $installment || ! in_array($installment->status, ['pending', 'partially_paid'], true)) {
            return 0;
        }

        $outstanding = ContributionBalance::outstandingString($installment);
        if (! MoneyMath::isPositive($outstanding)) {
            return 0;
        }

        $payAmount = $fullAmount
            ? $outstanding
            : MoneyMath::normalize(MoneyMath::toApiNumber($outstanding) / 2);

        $key = 'demo_p_'.$family->id.'_'.$installment->id;
        if ($this->paymentExists($tenantId, $key)) {
            return 0;
        }

        $ledger->createPayment($tenantId, $userId, [
            'family_id' => $family->id,
            'payer_name' => $payer,
            'payment_date' => $installment->due_date?->format('Y-m-d') ?? now()->toDateString(),
            'amount' => $payAmount,
            'method' => 'cash',
            'source_type' => 'project',
            'idempotency_key' => $key,
            'notes' => self::MARKER,
            'allocations' => [
                [
                    'allocatable_type' => 'project_installment',
                    'allocatable_id' => $installment->id,
                    'amount' => $payAmount,
                ],
            ],
        ]);

        return 1;
    }

    private function payAnnualDue(
        DonationLedgerService $ledger,
        int $tenantId,
        int $userId,
        Family $family,
        string $payer,
    ): int {
        $due = ContributionDue::forTenant($tenantId)
            ->where('family_id', $family->id)
            ->whereHas('plan', fn ($q) => $q->where('code', 'DEMO_ANNUAL_PARISH'))
            ->whereIn('status', ['pending', 'partially_paid'])
            ->orderBy('due_date')
            ->first();

        if (! $due) {
            return 0;
        }

        $outstanding = ContributionBalance::outstandingString($due);
        if (! MoneyMath::isPositive($outstanding)) {
            return 0;
        }

        $key = 'demo_a_'.$family->id.'_'.$due->id;
        if ($this->paymentExists($tenantId, $key)) {
            return 0;
        }

        $ledger->createPayment($tenantId, $userId, [
            'family_id' => $family->id,
            'payer_name' => $payer,
            'payment_date' => now()->subMonths(2)->toDateString(),
            'amount' => $outstanding,
            'method' => 'cheque',
            'source_type' => 'contribution',
            'idempotency_key' => $key,
            'notes' => self::MARKER,
            'allocations' => [
                [
                    'allocatable_type' => 'due',
                    'allocatable_id' => $due->id,
                    'amount' => $outstanding,
                ],
            ],
        ]);

        return 1;
    }

    private function paymentExists(int $tenantId, string $key): bool
    {
        return DonationPayment::forTenant($tenantId)
            ->where('idempotency_key', $key)
            ->exists();
    }

    private function reportVerification(int $tenantId): void
    {
        $errors = [];

        $planCount = ContributionPlan::forTenant($tenantId)->where('code', 'like', 'DEMO_%')->count();
        if ($planCount < 4) {
            $errors[] = 'Expected at least 4 demo contribution plans.';
        }

        $projectCount = DonationProject::forTenant($tenantId)->where('code', 'like', 'DEMO_%')->count();
        if ($projectCount < 8) {
            $errors[] = 'Expected at least 8 demo projects.';
        }

        $bccIds = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('bcc_id')
            ->distinct()
            ->pluck('bcc_id');

        foreach ($bccIds as $bccId) {
            $familyIds = Family::query()->where('tenant_id', $tenantId)->where('bcc_id', $bccId)->pluck('id');
            $dueFamilies = ContributionDue::forTenant($tenantId)->whereIn('family_id', $familyIds)->distinct()->count('family_id');
            if ($dueFamilies === 0) {
                $errors[] = 'BCC '.$bccId.' has no contribution dues.';
            }
        }

        $statuses = ContributionDue::forTenant($tenantId)
            ->whereHas('plan', fn ($q) => $q->where('code', 'like', 'DEMO_%'))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $paid = (int) ($statuses['paid'] ?? 0);
        $pending = (int) ($statuses['pending'] ?? 0);
        $partial = (int) ($statuses['partially_paid'] ?? 0);

        if ($paid === 0 || $pending === 0) {
            $errors[] = 'Demo dues should include both paid and pending rows (paid='.$paid.', pending='.$pending.').';
        }

        if ($errors === []) {
            $this->command?->info(sprintf(
                'Verification passed. Demo dues — paid: %d, partial: %d, pending: %d.',
                $paid,
                $partial,
                $pending
            ));
        } else {
            foreach ($errors as $error) {
                $this->command?->error($error);
            }
        }
    }
}
