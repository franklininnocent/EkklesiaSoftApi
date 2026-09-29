<?php

namespace Modules\BCC\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Models\FamilyMember;

class BccLeadershipService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VACATED = 'vacated';

    public const STATUS_TERMINATED = 'terminated';

    public const EXIT_REASONS = [
        'resigned',
        'transferred',
        'removed',
        'term_completed',
        'deceased',
        'handover',
    ];

    public const ROLES = [
        'leader',
        'coordinator',
        'assistant',
        'secretary',
        'treasurer',
        'animator',
        'other',
    ];

    public function __construct(
        private readonly BccAuditService $auditService,
        private readonly BccFamilyMembershipService $membershipService,
    ) {}

    public function current(int $tenantId, string $bccId): array
    {
        $this->membershipService->findBcc($tenantId, $bccId);

        $leaders = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('is_active', true)
            ->with(['member.family'])
            ->orderByRaw("CASE WHEN role = 'leader' THEN 0 ELSE 1 END")
            ->orderBy('appointed_date')
            ->get();

        return [
            'active_count' => $leaders->count(),
            'primary_leader' => $leaders->firstWhere('role', 'leader'),
            'by_role' => $leaders->groupBy('role')->map->values(),
            'leaders' => $leaders->values(),
        ];
    }

    public function timeline(int $tenantId, string $bccId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->membershipService->findBcc($tenantId, $bccId);

        $query = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->with(['member.family'])
            ->orderByDesc('appointed_date')
            ->orderByDesc('created_at');

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    public function eligibleMembers(int $tenantId, string $bccId): Collection
    {
        $this->membershipService->findBcc($tenantId, $bccId);

        return FamilyMember::query()
            ->where('status', 'active')
            ->whereHas('family', function ($q) use ($tenantId, $bccId): void {
                $q->where('tenant_id', $tenantId)->where('bcc_id', $bccId);
            })
            ->with('family:id,family_name,family_code')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function assign(int $tenantId, string $bccId, array $payload): BCCLeader
    {
        $bcc = $this->membershipService->findBcc($tenantId, $bccId);
        if ($bcc->status !== 'active') {
            throw new BccDomainException('Only an active BCC can receive leadership assignments.');
        }

        $member = $this->requireMemberInBcc($tenantId, $bccId, $payload['family_member_id']);
        $role = $payload['role'];

        $this->assertNoOverlap($bccId, $member->id, $role);

        $leader = BCCLeader::create([
            'tenant_id' => $tenantId,
            'bcc_id' => $bccId,
            'family_member_id' => $member->id,
            'role' => $role,
            'role_description' => $payload['role_description'] ?? null,
            'appointed_date' => $payload['appointment_date'],
            'term_start_date' => $payload['effective_from'],
            'term_end_date' => $payload['effective_to'] ?? null,
            'term_label' => $payload['term_label'] ?? null,
            'appointment_reference' => $payload['appointment_reference'] ?? null,
            'is_interim' => $payload['is_interim'] ?? false,
            'is_active' => true,
            'status' => self::STATUS_ACTIVE,
            'remarks' => $payload['remarks'] ?? null,
            'leader_phone' => $payload['leader_phone'] ?? null,
            'leader_email' => $payload['leader_email'] ?? null,
            'responsibilities' => $payload['responsibilities'] ?? null,
            'notes' => $payload['remarks'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->auditService->log(
            $tenantId,
            'leadership.assigned',
            'leadership',
            $leader->id,
            null,
            [
                'family_member_id' => $member->id,
                'member_name' => $member->full_name_display,
                'role' => $role,
                'appointment_date' => $leader->appointed_date?->toDateString(),
                'effective_from' => $leader->term_start_date?->toDateString(),
                'effective_to' => $leader->term_end_date?->toDateString(),
                'term_label' => $leader->term_label,
                'is_interim' => $leader->is_interim,
            ],
            $bccId,
        );

        return $leader->load('member.family');
    }

    public function terminate(int $tenantId, string $bccId, string $leaderId, array $payload): BCCLeader
    {
        $this->membershipService->findBcc($tenantId, $bccId);

        $leader = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('is_active', true)
            ->whereKey($leaderId)
            ->first();

        if ($leader === null) {
            throw BccDomainException::notFound('Leadership assignment not found.');
        }

        $effectiveTo = $payload['effective_to'];
        $termStart = $leader->term_start_date?->toDateString()
            ?? $leader->appointed_date?->toDateString();
        if ($termStart !== null && $effectiveTo < $termStart) {
            throw new BccDomainException('End date must be on or after the effective from date.');
        }

        $old = $this->termSnapshot($leader);
        $exitReason = $payload['exit_reason'];
        $leader->is_active = false;
        $leader->term_end_date = $effectiveTo;
        $leader->status = $exitReason === 'term_completed'
            ? self::STATUS_COMPLETED
            : ($exitReason === 'resigned' || $exitReason === 'transferred' || $exitReason === 'handover'
                ? self::STATUS_VACATED
                : self::STATUS_TERMINATED);
        $leader->exit_reason = $exitReason;
        $leader->remarks = $payload['remarks'] ?? $leader->remarks;
        $leader->notes = $payload['remarks'] ?? $leader->notes;
        $leader->updated_by = Auth::id();
        $leader->save();

        $this->auditService->log(
            $tenantId,
            'leadership.ended',
            'leadership',
            $leader->id,
            $old,
            $this->termSnapshot($leader),
            $bccId,
            ['reason' => $exitReason],
        );

        return $leader->load('member.family');
    }

    public function handover(int $tenantId, string $bccId, array $payload): array
    {
        return DB::transaction(function () use ($tenantId, $bccId, $payload) {
            $incomingMemberId = $payload['incoming_family_member_id'];
            $outgoingPreview = BCCLeader::query()
                ->where('bcc_id', $bccId)
                ->where('is_active', true)
                ->whereKey($payload['outgoing_leader_id'])
                ->first();

            if ($outgoingPreview !== null && $outgoingPreview->family_member_id === $incomingMemberId) {
                throw new BccDomainException('Incoming leader must be a different BCC member.');
            }

            $outgoing = $this->terminate($tenantId, $bccId, $payload['outgoing_leader_id'], [
                'effective_to' => $payload['outgoing_effective_to'],
                'exit_reason' => $payload['outgoing_exit_reason'],
            ]);

            $incoming = $this->assign($tenantId, $bccId, [
                'family_member_id' => $incomingMemberId,
                'role' => $payload['role'] ?? $outgoing->role,
                'appointment_date' => $payload['appointment_date'],
                'effective_from' => $payload['effective_from'],
                'effective_to' => $payload['effective_to'] ?? null,
                'term_label' => $payload['term_label'] ?? null,
                'appointment_reference' => $payload['appointment_reference'] ?? null,
                'is_interim' => $payload['is_interim'] ?? false,
                'remarks' => $payload['remarks'] ?? null,
                'responsibilities' => $payload['responsibilities'] ?? null,
            ]);

            $this->auditService->log(
                $tenantId,
                'leadership.handover',
                'leadership',
                $incoming->id,
                ['outgoing_leader_id' => $outgoing->id],
                ['incoming_leader_id' => $incoming->id],
                $bccId,
            );

            return [
                'outgoing' => $outgoing,
                'incoming' => $incoming,
            ];
        });
    }

    private function requireMemberInBcc(int $tenantId, string $bccId, string $familyMemberId): FamilyMember
    {
        $member = FamilyMember::query()
            ->whereKey($familyMemberId)
            ->where('status', 'active')
            ->whereHas('family', function ($q) use ($tenantId, $bccId): void {
                $q->where('tenant_id', $tenantId)->where('bcc_id', $bccId);
            })
            ->first();

        if ($member === null) {
            throw new BccDomainException('Leader must be an active member of a family assigned to this BCC.');
        }

        return $member;
    }

    private function assertNoOverlap(string $bccId, string $familyMemberId, string $role, ?string $exceptId = null): void
    {
        $sameRole = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('family_member_id', $familyMemberId)
            ->where('role', $role)
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();

        if ($sameRole !== null) {
            throw BccDomainException::conflict(
                'This member already holds this active role in the BCC.',
                ['conflict' => ['type' => 'leadership_overlap', 'existing_leader_id' => $sameRole->id]],
            );
        }

        if ($role === 'leader') {
            $existingPrimary = BCCLeader::query()
                ->where('bcc_id', $bccId)
                ->where('role', 'leader')
                ->where('is_active', true)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->with('member')
                ->first();

            if ($existingPrimary !== null) {
                throw BccDomainException::conflict(
                    'This BCC already has an active primary leader.',
                    [
                        'conflict' => [
                            'type' => 'leadership_overlap',
                            'existing_leader_id' => $existingPrimary->id,
                            'existing_holder' => $existingPrimary->member?->full_name_display,
                        ],
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function termSnapshot(BCCLeader $leader): array
    {
        return [
            'id' => $leader->id,
            'family_member_id' => $leader->family_member_id,
            'role' => $leader->role,
            'appointment_date' => $leader->appointed_date?->toDateString(),
            'effective_from' => $leader->term_start_date?->toDateString(),
            'effective_to' => $leader->term_end_date?->toDateString(),
            'term_label' => $leader->term_label,
            'appointment_reference' => $leader->appointment_reference,
            'is_interim' => $leader->is_interim,
            'is_active' => $leader->is_active,
            'status' => $leader->status,
            'exit_reason' => $leader->exit_reason,
        ];
    }
}
