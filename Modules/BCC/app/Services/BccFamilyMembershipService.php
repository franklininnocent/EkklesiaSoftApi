<?php

namespace Modules\BCC\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class BccFamilyMembershipService
{
    public function __construct(private readonly BccAuditService $auditService) {}

    public function findBcc(int $tenantId, string $bccId): BCC
    {
        $bcc = BCC::query()->forTenant((string) $tenantId)->find($bccId);
        if ($bcc === null) {
            throw BccDomainException::notFound();
        }

        return $bcc;
    }

    /**
     * Keep families.bcc_id and interval rows aligned (Family form + BCC Members tab).
     */
    public function syncFromFamilyPointer(Family $family, ?string $previousBccId, int $tenantId): void
    {
        $newBccId = $family->bcc_id ?: null;
        $previousBccId = $previousBccId ?: null;

        if ($newBccId === $previousBccId) {
            return;
        }

        if ($previousBccId !== null) {
            $this->closeCurrentForFamily($tenantId, $family->id, $previousBccId, 'family_profile_change');
        }

        if ($newBccId !== null) {
            $this->openCurrentForFamily($tenantId, $newBccId, $family, now()->toDateString(), 'family_profile');
        }
    }

    /**
     * @param  list<string>  $familyIds
     * @return array{assigned: int, transferred: int}
     */
    public function assignFamilies(
        int $tenantId,
        string $bccId,
        array $familyIds,
        bool $transfer = false,
        ?string $joinedDate = null,
    ): array {
        $bcc = $this->findBcc($tenantId, $bccId);
        if ($bcc->status !== 'active') {
            throw new BccDomainException('Only an active BCC can accept families.');
        }

        $joinedDate = $joinedDate ?: now()->toDateString();
        $assigned = 0;
        $transferred = 0;

        DB::transaction(function () use ($tenantId, $bcc, $familyIds, $transfer, $joinedDate, &$assigned, &$transferred): void {
            foreach ($familyIds as $familyId) {
                $family = Family::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($familyId)
                    ->first();

                if ($family === null) {
                    throw BccDomainException::notFound('Family not found.');
                }

                if ($family->status === 'migrated') {
                    throw new BccDomainException('Transferred families cannot be assigned to a BCC.');
                }

                $current = BccFamilyMembership::query()
                    ->forTenant($tenantId)
                    ->current()
                    ->where('family_id', $family->id)
                    ->first();

                $currentBccId = $current?->bcc_id ?? $family->bcc_id;

                if ($currentBccId !== null) {
                    if ($currentBccId === $bcc->id) {
                        throw new BccDomainException('This family is already in this BCC.');
                    }

                    if (! $transfer) {
                        $other = BCC::query()->find($currentBccId);
                        throw BccDomainException::conflict(
                            'This family already belongs to another BCC.',
                            [
                                'conflict' => [
                                    'type' => 'bcc_membership_exists',
                                    'family_id' => $family->id,
                                    'existing_bcc_id' => $currentBccId,
                                    'existing_bcc_name' => $other?->name,
                                ],
                            ],
                        );
                    }

                    $this->closeCurrentForFamily($tenantId, $family->id, $currentBccId, 'transferred');
                    if ($family->bcc_id === $currentBccId) {
                        $family->bcc_id = null;
                        $family->save();
                    }
                    $transferred++;
                }

                $this->openCurrentForFamily(
                    $tenantId,
                    $bcc->id,
                    $family,
                    $joinedDate,
                    $currentBccId ? 'transfer' : 'assign',
                );
                $assigned++;
            }
        });

        return ['assigned' => $assigned, 'transferred' => $transferred];
    }

    public function removeMembership(
        int $tenantId,
        string $bccId,
        string $membershipId,
        ?string $exitDate = null,
        ?string $exitReason = null,
    ): BccFamilyMembership {
        $this->findBcc($tenantId, $bccId);

        $membership = BccFamilyMembership::query()
            ->forTenant($tenantId)
            ->forBcc($bccId)
            ->current()
            ->whereKey($membershipId)
            ->first();

        if ($membership === null) {
            throw BccDomainException::notFound('Membership not found.');
        }

        DB::transaction(function () use ($tenantId, $membership, $exitDate, $exitReason): void {
            $this->closeMembership($tenantId, $membership, $exitDate, $exitReason ?: 'removed');
        });

        return $membership->fresh();
    }

    /**
     * @param  list<string>  $familyIds
     */
    public function removeFamilies(int $tenantId, array $familyIds, ?string $bccId = null): int
    {
        $removed = 0;

        DB::transaction(function () use ($tenantId, $familyIds, $bccId, &$removed): void {
            $query = BccFamilyMembership::query()
                ->forTenant($tenantId)
                ->current()
                ->whereIn('family_id', $familyIds);

            if ($bccId !== null) {
                $query->forBcc($bccId);
            }

            $memberships = $query->get();
            foreach ($memberships as $membership) {
                $this->closeMembership($tenantId, $membership, null, 'removed');
                $removed++;
            }

            $leftover = Family::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $familyIds)
                ->whereNotNull('bcc_id')
                ->when($bccId, fn ($q) => $q->where('bcc_id', $bccId))
                ->get();

            foreach ($leftover as $family) {
                $this->closeCurrentForFamily($tenantId, $family->id, (string) $family->bcc_id, 'removed');
                if ($family->bcc_id !== null) {
                    $family->bcc_id = null;
                    $family->save();
                    $removed++;
                }
            }
        });

        return $removed;
    }

    public function paginateMembers(int $tenantId, string $bccId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->findBcc($tenantId, $bccId);

        $query = BccFamilyMembership::query()
            ->forTenant($tenantId)
            ->forBcc($bccId)
            ->with(['family' => function ($q): void {
                $q->withCount('members');
            }])
            ->orderByDesc('joined_date')
            ->orderByDesc('created_at');

        if (($filters['is_current'] ?? null) === 'true' || ($filters['is_current'] ?? null) === true) {
            $query->current();
        } elseif (($filters['is_current'] ?? null) === 'false' || ($filters['is_current'] ?? null) === false) {
            $query->where('is_current', false);
        } else {
            $query->current();
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('family', function ($q) use ($search): void {
                $like = BccAgeBands::likeOperator();
                $q->where('family_name', $like, "%{$search}%")
                    ->orWhere('family_code', $like, "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->whereHas('family', fn ($q) => $q->where('status', $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function paginatePeople(int $tenantId, string $bccId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->findBcc($tenantId, $bccId);

        $query = FamilyMember::query()
            ->whereHas('family', function ($q) use ($tenantId, $bccId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('bcc_id', $bccId)
                    ->whereNull('deleted_at');
            })
            ->with(['family:id,family_name,family_code,bcc_id,status']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $like = BccAgeBands::likeOperator();
                $q->where('first_name', $like, "%{$search}%")
                    ->orWhere('last_name', $like, "%{$search}%")
                    ->orWhere('middle_name', $like, "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['gender'])) {
            // Match the normalisation used by the overview aggregates so a
            // drill-down from the dashboard returns exactly the counted rows.
            if ($filters['gender'] === 'unknown') {
                $query->where(function ($q): void {
                    $q->whereNull('gender')
                        ->orWhereRaw("LOWER(TRIM(gender)) IN ('', 'unknown')");
                });
            } else {
                $query->whereRaw('LOWER(TRIM(gender)) = ?', [$filters['gender']]);
            }
        }

        if (! empty($filters['age_band']) && in_array($filters['age_band'], BccAgeBands::keys(), true)) {
            if ($filters['age_band'] === BccAgeBands::UNKNOWN) {
                $query->whereNull('date_of_birth');
            } else {
                $band = collect(BccAgeBands::definitions())->firstWhere('key', $filters['age_band']);
                if ($band !== null) {
                    $query->whereNotNull('date_of_birth');
                    $maxDob = now()->subYears($band['min'] ?? 0)->toDateString();
                    $query->whereDate('date_of_birth', '<=', $maxDob);
                    if ($band['max'] !== null) {
                        $minDob = now()->subYears($band['max'] + 1)->addDay()->toDateString();
                        $query->whereDate('date_of_birth', '>=', $minDob);
                    }
                }
            }
        }

        $query->orderBy('last_name')->orderBy('first_name');

        return $query->paginate($perPage);
    }

    public function lookupFamilies(int $tenantId, string $search, int $perPage = 20, ?string $excludeBccId = null): LengthAwarePaginator
    {
        $query = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'migrated')
            ->withCount('members')
            ->with('bcc:id,name,bcc_code');

        if (strlen($search) >= 2) {
            $query->where(function ($q) use ($search): void {
                $like = BccAgeBands::likeOperator();
                $q->where('family_name', $like, "%{$search}%")
                    ->orWhere('family_code', $like, "%{$search}%")
                    ->orWhere('head_of_family', $like, "%{$search}%");
            });
        }

        if ($excludeBccId) {
            $query->where(function ($q) use ($excludeBccId): void {
                $q->whereNull('bcc_id')->orWhere('bcc_id', '!=', $excludeBccId);
            });
        }

        return $query->orderBy('family_name')->paginate($perPage);
    }

    public function paginateHistory(int $tenantId, string $bccId, int $perPage): LengthAwarePaginator
    {
        $this->findBcc($tenantId, $bccId);

        return BccFamilyMembership::query()
            ->forTenant($tenantId)
            ->forBcc($bccId)
            ->with(['family:id,family_name,family_code,status', 'creator:id,name,email'])
            ->orderByDesc('joined_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    private function openCurrentForFamily(
        int $tenantId,
        string $bccId,
        Family $family,
        string $joinedDate,
        string $source,
    ): BccFamilyMembership {
        $membership = BccFamilyMembership::create([
            'tenant_id' => $tenantId,
            'bcc_id' => $bccId,
            'family_id' => $family->id,
            'status' => BccFamilyMembership::STATUS_ACTIVE,
            'joined_date' => $joinedDate,
            'is_current' => true,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $family->bcc_id = $bccId;
        $family->save();

        $this->auditService->log(
            $tenantId,
            $source === 'transfer' ? 'membership.transferred' : 'membership.assigned',
            'membership',
            $membership->id,
            null,
            [
                'family_id' => $family->id,
                'family_name' => $family->family_name,
                'bcc_id' => $bccId,
                'joined_date' => $joinedDate,
            ],
            $bccId,
            ['source' => $source],
        );

        return $membership;
    }

    private function closeCurrentForFamily(int $tenantId, string $familyId, string $bccId, string $reason): void
    {
        $membership = BccFamilyMembership::query()
            ->forTenant($tenantId)
            ->forBcc($bccId)
            ->current()
            ->where('family_id', $familyId)
            ->first();

        if ($membership !== null) {
            $this->closeMembership($tenantId, $membership, null, $reason);
        }
    }

    private function closeMembership(
        int $tenantId,
        BccFamilyMembership $membership,
        ?string $exitDate,
        string $reason,
    ): void {
        $old = $membership->only(['status', 'is_current', 'exit_date', 'bcc_id', 'family_id']);

        $membership->status = BccFamilyMembership::STATUS_EXITED;
        $membership->is_current = false;
        $membership->exit_date = $exitDate ?: now()->toDateString();
        $membership->exit_reason = $reason;
        $membership->updated_by = Auth::id();
        $membership->save();

        Family::query()
            ->where('id', $membership->family_id)
            ->where('bcc_id', $membership->bcc_id)
            ->update(['bcc_id' => null]);

        $this->vacateFamilyLeadership($tenantId, $membership->bcc_id, $membership->family_id);

        $this->auditService->log(
            $tenantId,
            'membership.removed',
            'membership',
            $membership->id,
            $old,
            $membership->only(['status', 'is_current', 'exit_date', 'exit_reason']),
            $membership->bcc_id,
            ['family_id' => $membership->family_id],
        );
    }

    private function vacateFamilyLeadership(int $tenantId, string $bccId, string $familyId): void
    {
        $leaders = BCCLeader::query()
            ->where('bcc_id', $bccId)
            ->where('is_active', true)
            ->whereHas('member', fn ($q) => $q->where('family_id', $familyId))
            ->get();

        foreach ($leaders as $leader) {
            $old = $leader->only(['is_active', 'status', 'term_end_date', 'role', 'family_member_id']);
            $leader->is_active = false;
            $leader->term_end_date = $leader->term_end_date ?: now()->toDateString();
            $leader->status = BccLeadershipService::STATUS_VACATED;
            $leader->exit_reason = $leader->exit_reason ?: 'transferred';
            $leader->updated_by = Auth::id();
            $leader->save();

            $this->auditService->log(
                $tenantId,
                'leadership.ended',
                'leadership',
                $leader->id,
                $old,
                $leader->only(['is_active', 'status', 'term_end_date', 'exit_reason']),
                $bccId,
                ['reason' => 'family_removed', 'family_id' => $familyId],
            );
        }
    }
}
