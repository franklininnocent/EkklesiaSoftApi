<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;

class ProjectInstallmentDueService
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationProjectService $projectService
    ) {}

    /**
     * @return array<int, ProjectInstallmentDue>
     */
    public function generateForProject(int $tenantId, int $userId, DonationProject $project, ?array $familyIds = null): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $project, $familyIds): array {
            $asOfDate = ($project->start_date ?? now())->toDateString();
            $familyIds ??= $this->projectService->getEnrolledFamilyIds($project, $asOfDate);
            $installmentCount = max(1, (int) $project->installment_count);
            $created = [];

            foreach ($familyIds as $familyId) {
                $familyTarget = $this->projectService->resolveFamilyTarget($project, $familyId, $asOfDate);
                if ($familyTarget === null || $familyTarget <= 0) {
                    continue;
                }

                $perInstallment = round($familyTarget / $installmentCount, 2);
                $allocated = 0;

                for ($i = 1; $i <= $installmentCount; $i++) {
                    $amount = $i === $installmentCount
                        ? round($familyTarget - $allocated, 2)
                        : $perInstallment;
                    $allocated += $amount;

                    $dueDate = $this->resolveInstallmentDueDate($project, $i);
                    $due = $this->upsertInstallment(
                        $tenantId,
                        $userId,
                        $project,
                        $familyId,
                        $i,
                        "{$i}/{$installmentCount}",
                        $dueDate,
                        $amount
                    );

                    if ($due) {
                        $created[] = $due;
                    }
                }
            }

            $this->auditService->log(
                $tenantId,
                'project.installments_generated',
                'project',
                $project->id,
                null,
                ['count' => count($created)],
                ['family_ids' => $familyIds]
            );

            return $created;
        });
    }

    public function waiveDue(int $tenantId, int $userId, ProjectInstallmentDue $due, ?string $reason = null): ProjectInstallmentDue
    {
        $oldStatus = $due->status;
        $due->status = 'waived';
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Waived'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'project_installment.waived',
            'project_installment',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'waived']
        );

        return $due->fresh(['family', 'project']);
    }

    public function cancelDue(int $tenantId, int $userId, ProjectInstallmentDue $due, ?string $reason = null): ProjectInstallmentDue
    {
        $oldStatus = $due->status;
        $due->status = 'cancelled';
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Cancelled'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'project_installment.cancelled',
            'project_installment',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled']
        );

        return $due->fresh(['family', 'project']);
    }

    public function applyPayment(int $tenantId, int $userId, ProjectInstallmentDue $due, string|float|int $amount): void
    {
        ContributionBalance::applyPaid($due, $amount);
        $due->status = ContributionBalance::statusFromPaid($due);
        $due->updated_by = $userId;
        $due->save();

        $project = DonationProject::forTenant($tenantId)->find($due->project_id);
        if ($project) {
            $this->projectService->recordCollection($tenantId, $project, $due->family_id, $amount);
        }
    }

    public function reversePayment(int $tenantId, int $userId, ProjectInstallmentDue $due, string|float|int $amount): void
    {
        ContributionBalance::unwindPaid($due, $amount);
        $due->status = ContributionBalance::statusFromPaid($due);
        $due->updated_by = $userId;
        $due->save();

        $project = DonationProject::forTenant($tenantId)->find($due->project_id);
        if ($project) {
            $this->projectService->reverseCollection($tenantId, $project, $due->family_id, $amount);
        }
    }

    private function upsertInstallment(
        int $tenantId,
        int $userId,
        DonationProject $project,
        string $familyId,
        int $installmentNumber,
        string $label,
        string $dueDate,
        float $amountDue
    ): ?ProjectInstallmentDue {
        $existing = ProjectInstallmentDue::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('installment_number', $installmentNumber)
            ->first();

        if ($existing && in_array($existing->status, ['paid', 'waived', 'cancelled'], true)) {
            return null;
        }

        if ($existing) {
            $existing->installment_label = $label;
            $existing->due_date = $dueDate;
            $existing->amount_due = $amountDue;
            $existing->updated_by = $userId;
            $existing->save();

            return $existing->fresh(['family', 'project']);
        }

        return ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $familyId,
            'installment_number' => $installmentNumber,
            'installment_label' => $label,
            'due_date' => $dueDate,
            'amount_due' => $amountDue,
            'amount_paid' => 0,
            'status' => 'pending',
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->fresh(['family', 'project']);
    }

    private function resolveInstallmentDueDate(DonationProject $project, int $installmentNumber): string
    {
        $start = Carbon::parse($project->start_date ?? now());

        if (! $project->installment_frequency || $installmentNumber <= 1) {
            return $start->toDateString();
        }

        $frequency = $project->installment_frequency;
        if ($frequency === 'custom') {
            $days = max(1, (int) ($project->installment_interval_days ?? 30));

            return $start->copy()->addDays(($installmentNumber - 1) * $days)->toDateString();
        }

        return match ($frequency) {
            'weekly' => $start->copy()->addWeeks($installmentNumber - 1)->endOfWeek()->toDateString(),
            'monthly' => $start->copy()->addMonths($installmentNumber - 1)->endOfMonth()->toDateString(),
            'quarterly' => $start->copy()->addMonths(($installmentNumber - 1) * 3)->endOfMonth()->toDateString(),
            'half_yearly' => $start->copy()->addMonths(($installmentNumber - 1) * 6)->endOfMonth()->toDateString(),
            'yearly' => $start->copy()->addYears($installmentNumber - 1)->endOfYear()->toDateString(),
            default => $start->copy()->addMonths($installmentNumber - 1)->endOfMonth()->toDateString(),
        };
    }
}
