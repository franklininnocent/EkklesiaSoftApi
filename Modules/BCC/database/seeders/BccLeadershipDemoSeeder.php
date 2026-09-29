<?php

namespace Modules\BCC\Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Services\BccAuditService;
use Modules\BCC\Services\BccLeadershipService;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * Seeds five leadership positions per BCC using BccLeadershipService (active BCCs).
 *
 * Role codes match BccLeadershipService::ROLES; parish titles live in role_description.
 *
 * Run:
 * BCC_LEADERSHIP_DEMO_TENANT_ID=1 php artisan db:seed --class=Modules\\BCC\\Database\\Seeders\\BccLeadershipDemoSeeder
 */
class BccLeadershipDemoSeeder extends Seeder
{
    public const MARKER = 'bcc_leadership_demo_v1';

    /** @var list<array{role: string, title: string, responsibilities: string}> */
    public const POSITIONS = [
        [
            'role' => 'leader',
            'title' => 'President',
            'responsibilities' => 'Chairs BCC meetings, represents the community to the parish council, and coordinates pastoral priorities.',
        ],
        [
            'role' => 'coordinator',
            'title' => 'Vice President',
            'responsibilities' => 'Supports the president, leads outreach visits, and steps in when the president is unavailable.',
        ],
        [
            'role' => 'secretary',
            'title' => 'Secretary',
            'responsibilities' => 'Maintains meeting minutes, membership rolls, and correspondence with the parish office.',
        ],
        [
            'role' => 'assistant',
            'title' => 'Assistant Secretary',
            'responsibilities' => 'Assists the secretary with records, notices, and follow-up on action items.',
        ],
        [
            'role' => 'treasurer',
            'title' => 'Cashier',
            'responsibilities' => 'Tracks BCC collections, expenses, and reconciliation for parish reporting.',
        ],
    ];

    public function run(): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found. Set BCC_LEADERSHIP_DEMO_TENANT_ID.');

            return;
        }

        $actor = $this->resolveActor((int) $tenant->id);
        if (! $actor) {
            $this->command?->error('No user found to attribute leadership assignments.');

            return;
        }

        Auth::login($actor);
        app()->instance(TenantContext::class, new TenantContext(
            (int) $actor->id,
            $actor->tenant_id ? (int) $actor->tenant_id : (int) $tenant->id,
            (int) $tenant->id,
            null,
            null,
        ));

        $leadership = app(BccLeadershipService::class);
        $audit = app(BccAuditService::class);

        $bccs = BCC::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $assigned = 0;
        $skipped = 0;

        foreach ($bccs as $bcc) {
            $eligible = $this->rankEligibleMembers($leadership->eligibleMembers((int) $tenant->id, (string) $bcc->id));
            if ($eligible->count() < count(self::POSITIONS)) {
                $this->command?->warn(sprintf(
                    'BCC "%s" has only %d eligible adults; need %d distinct leaders.',
                    $bcc->name,
                    $eligible->count(),
                    count(self::POSITIONS)
                ));
            }

            $term = $this->termDatesForBcc($bcc);
            $pool = $eligible
                ->reject(fn (FamilyMember $m) => $this->memberHasActiveLeadership((string) $bcc->id, (string) $m->id))
                ->values();
            $pickedMembers = $this->pickDistinctMembers($pool, (string) $bcc->id, count(self::POSITIONS));
            $pickIndex = 0;

            foreach (self::POSITIONS as $position) {
                if ($this->hasActiveRoleHolder((string) $bcc->id, $position['role'])) {
                    $skipped++;

                    continue;
                }

                $member = null;
                while ($pickIndex < count($pickedMembers)) {
                    $candidate = $pickedMembers[$pickIndex++];
                    if (! $this->memberHasActiveLeadership((string) $bcc->id, (string) $candidate->id)) {
                        $member = $candidate;
                        break;
                    }
                }

                if ($member === null) {
                    $member = $pool->first(
                        fn (FamilyMember $m) => ! $this->memberHasActiveLeadership((string) $bcc->id, (string) $m->id)
                    );
                }

                if ($member === null) {
                    $this->command?->warn(sprintf('No member available for %s on BCC "%s".', $position['title'], $bcc->name));

                    continue;
                }

                $payload = [
                    'family_member_id' => $member->id,
                    'role' => $position['role'],
                    'role_description' => $position['title'],
                    'appointment_date' => $term['appointed'],
                    'effective_from' => $term['start'],
                    'effective_to' => $term['end'],
                    'term_label' => $term['label'],
                    'appointment_reference' => self::MARKER,
                    'is_interim' => false,
                    'remarks' => sprintf('Demo %s for %s.', $position['title'], $bcc->name),
                    'responsibilities' => $position['responsibilities'],
                    'leader_phone' => $member->phone,
                    'leader_email' => $member->email,
                ];

                try {
                    if ($bcc->status === 'active') {
                        $leadership->assign((int) $tenant->id, (string) $bcc->id, $payload);
                    } else {
                        $this->assignWithoutActiveBccCheck((int) $tenant->id, (string) $bcc->id, $payload, $audit);
                    }
                    $assigned++;
                } catch (\Throwable $e) {
                    $this->command?->warn(sprintf(
                        'Could not assign %s on "%s": %s',
                        $position['title'],
                        $bcc->name,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->command?->info(sprintf('Leadership demo: %d assignments created, %d roles already filled.', $assigned, $skipped));

        $errors = $this->verify((int) $tenant->id, $bccs);
        if ($errors === []) {
            $this->command?->info('Verification passed.');
        } else {
            foreach ($errors as $error) {
                $this->command?->error($error);
            }
        }
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = env('BCC_LEADERSHIP_DEMO_TENANT_ID');
        if ($tenantId) {
            return Tenant::query()->find($tenantId);
        }

        foreach (['Sacred Heart Church', 'Sacred Heart Parish'] as $name) {
            $tenant = Tenant::query()->where('name', $name)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()->where('name', 'ILIKE', '%Sacred Heart%')->orderBy('id')->first()
            ?? Tenant::query()->orderBy('id')->first();
    }

    private function resolveActor(int $tenantId): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();
    }

    /**
     * @return array{appointed: string, start: string, end: string, label: string}
     */
    private function termDatesForBcc(BCC $bcc): array
    {
        $start = $bcc->established_date
            ? Carbon::parse($bcc->established_date)->max(now()->subYears(2))->format('Y-m-d')
            : now()->subYears(2)->startOfYear()->format('Y-m-d');

        $appointed = Carbon::parse($start)->subDays(14)->format('Y-m-d');
        $end = Carbon::parse($start)->addYears(2)->format('Y-m-d');
        $label = Carbon::parse($start)->format('Y').'-'.Carbon::parse($end)->format('Y');

        return [
            'appointed' => $appointed,
            'start' => $start,
            'end' => $end,
            'label' => $label,
        ];
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return Collection<int, FamilyMember>
     */
    private function rankEligibleMembers(Collection $members): Collection
    {
        return $members
            ->filter(function (FamilyMember $member): bool {
                if (! $member->date_of_birth) {
                    return true;
                }

                return Carbon::parse($member->date_of_birth)->age >= 21;
            })
            ->sortBy(function (FamilyMember $member): string {
                $priority = in_array($member->relationship_to_head, ['self', 'spouse'], true) ? '0' : '1';

                return $priority.$member->last_name.$member->first_name;
            })
            ->values();
    }

    /**
     * @param  Collection<int, FamilyMember>  $eligible
     * @return list<FamilyMember>
     */
    private function pickDistinctMembers(Collection $eligible, string $bccId, int $count): array
    {
        $eligible = $eligible->values();
        $total = $eligible->count();
        if ($total === 0) {
            return [];
        }

        $offset = (int) (hexdec(substr(md5($bccId), 0, 8)) % max(1, $total));
        $picked = [];
        $seen = [];

        for ($i = 0; $i < $total && count($picked) < $count; $i++) {
            $member = $eligible->get(($offset + $i) % $total);
            if ($member === null) {
                continue;
            }
            if (isset($seen[$member->id])) {
                continue;
            }
            $seen[$member->id] = true;
            $picked[] = $member;
        }

        return $picked;
    }

    private function hasActiveRoleHolder(string $bccId, string $role): bool
    {
        return BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('role', $role)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function memberHasActiveLeadership(string $bccId, string $memberId): bool
    {
        return BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('family_member_id', $memberId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assignWithoutActiveBccCheck(
        int $tenantId,
        string $bccId,
        array $payload,
        BccAuditService $audit,
    ): BCCLeader {
        $member = FamilyMember::query()
            ->whereKey($payload['family_member_id'])
            ->where('status', 'active')
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId)->where('bcc_id', $bccId))
            ->firstOrFail();

        $leader = BCCLeader::create([
            'tenant_id' => $tenantId,
            'bcc_id' => $bccId,
            'family_member_id' => $member->id,
            'role' => $payload['role'],
            'role_description' => $payload['role_description'] ?? null,
            'appointed_date' => $payload['appointment_date'],
            'term_start_date' => $payload['effective_from'],
            'term_end_date' => $payload['effective_to'] ?? null,
            'term_label' => $payload['term_label'] ?? null,
            'appointment_reference' => $payload['appointment_reference'] ?? null,
            'is_interim' => $payload['is_interim'] ?? false,
            'is_active' => true,
            'status' => BccLeadershipService::STATUS_ACTIVE,
            'remarks' => $payload['remarks'] ?? null,
            'leader_phone' => $payload['leader_phone'] ?? null,
            'leader_email' => $payload['leader_email'] ?? null,
            'responsibilities' => $payload['responsibilities'] ?? null,
            'notes' => $payload['remarks'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $audit->log(
            $tenantId,
            'leadership.assigned',
            'leadership',
            $leader->id,
            null,
            [
                'family_member_id' => $member->id,
                'member_name' => $member->full_name_display,
                'role' => $payload['role'],
                'appointment_date' => $leader->appointed_date?->toDateString(),
                'effective_from' => $leader->term_start_date?->toDateString(),
                'effective_to' => $leader->term_end_date?->toDateString(),
                'term_label' => $leader->term_label,
                'is_interim' => $leader->is_interim,
                'source' => self::MARKER,
            ],
            $bccId,
        );

        return $leader;
    }

    /**
     * @param  Collection<int, BCC>  $bccs
     * @return list<string>
     */
    private function verify(int $tenantId, Collection $bccs): array
    {
        $errors = [];
        $requiredRoles = array_column(self::POSITIONS, 'role');

        foreach ($bccs as $bcc) {
            foreach ($requiredRoles as $role) {
                $active = BCCLeader::query()
                    ->where('bcc_id', $bcc->id)
                    ->where('role', $role)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->count();

                if ($active !== 1) {
                    $errors[] = sprintf('BCC "%s" has %d active "%s" (expected 1).', $bcc->name, $active, $role);
                }
            }

            $leaders = BCCLeader::query()
                ->where('bcc_id', $bcc->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->with('member.family')
                ->get();

            foreach ($leaders as $leader) {
                $familyBcc = $leader->member?->family?->bcc_id;
                if ($familyBcc !== $bcc->id) {
                    $errors[] = sprintf(
                        'Leader %s on BCC "%s" belongs to family on BCC %s.',
                        $leader->id,
                        $bcc->name,
                        $familyBcc ?? 'none'
                    );
                }
            }

            $memberIds = $leaders->pluck('family_member_id')->filter();
            if ($memberIds->unique()->count() < min(count(self::POSITIONS), $memberIds->count())) {
                // duplicate holders across roles — warn only for demo marker rows
                $demoIds = $leaders->where('appointment_reference', self::MARKER)->pluck('family_member_id');
                if ($demoIds->unique()->count() < $demoIds->count()) {
                    $errors[] = sprintf('BCC "%s" demo leadership reuses the same member for multiple roles.', $bcc->name);
                }
            }
        }

        $crossBcc = (int) DB::table('bcc_leaders as bl')
            ->join('family_members as fm', 'fm.id', '=', 'bl.family_member_id')
            ->join('families as f', 'f.id', '=', 'fm.family_id')
            ->where('bl.tenant_id', $tenantId)
            ->where('bl.is_active', true)
            ->whereNull('bl.deleted_at')
            ->whereNull('fm.deleted_at')
            ->whereColumn('f.bcc_id', '!=', 'bl.bcc_id')
            ->count();

        if ($crossBcc > 0) {
            $errors[] = sprintf('%d active leaders are tied to families outside their BCC.', $crossBcc);
        }

        return $errors;
    }
}
