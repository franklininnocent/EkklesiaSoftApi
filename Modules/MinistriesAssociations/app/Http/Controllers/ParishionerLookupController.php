<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Http\Requests\ParishionerLookupRequest;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class ParishionerLookupController extends Controller
{
    use AuthorizesRequests;

    public function lookup(ParishionerLookupRequest $request): JsonResponse
    {
        $this->authorize('ministries.lookupParishioners');

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $query = FamilyMember::query()
            ->whereHas('family', function ($familyQuery) use ($tenantId): void {
                $familyQuery
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
            })
            ->with('family')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('first_name', 'ilike', $search)
                    ->orWhere('middle_name', 'ilike', $search)
                    ->orWhere('last_name', 'ilike', $search)
                    ->orWhere('phone', 'ilike', $search)
                    ->orWhereHas('family', function ($familyQuery) use ($search): void {
                        $familyQuery
                            ->where('family_code', 'ilike', $search)
                            ->orWhere('family_name', 'ilike', $search);
                    });
            });
        }

        if (! empty($validated['exclude_organization_id'])) {
            $excludeOrganizationId = $validated['exclude_organization_id'];

            $query->whereNotIn('id', function ($subQuery) use ($tenantId, $excludeOrganizationId): void {
                $subQuery
                    ->select('family_member_id')
                    ->from('ma_memberships')
                    ->where('tenant_id', $tenantId)
                    ->where('organization_id', $excludeOrganizationId)
                    ->where('member_source', OrganizationMembership::SOURCE_PARISH)
                    ->where('is_current', true)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->whereNull('deleted_at');
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        $memberIds = collect($paginator->items())->pluck('id')->all();
        $activeMinistryCounts = $this->activeMinistryCounts($tenantId, $memberIds);

        $items = collect($paginator->items())
            ->map(fn (FamilyMember $member) => $this->presentParishioner($member, $activeMinistryCounts))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    /**
     * @param  list<string>  $memberIds
     * @return array<string, int>
     */
    private function activeMinistryCounts(int $tenantId, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return OrganizationMembership::query()
            ->forTenant($tenantId)
            ->whereIn('family_member_id', $memberIds)
            ->where('member_source', OrganizationMembership::SOURCE_PARISH)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->groupBy('family_member_id')
            ->selectRaw('family_member_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'family_member_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, int>  $activeMinistryCounts
     * @return array<string, mixed>
     */
    private function presentParishioner(FamilyMember $member, array $activeMinistryCounts): array
    {
        $family = $member->family;
        $censusStatus = $this->censusStatus($member);
        $eligible = $this->isEligible($member);

        return [
            'family_member_id' => $member->id,
            'full_name' => $member->full_name_display,
            'family_id' => $member->family_id,
            'family_name' => $family?->family_name,
            'family_code' => $family?->family_code,
            'gender' => $member->gender,
            'phone' => $member->phone,
            'photo_url' => $this->photoUrl($member),
            'census_status' => $censusStatus,
            'active_ministry_count' => $activeMinistryCounts[$member->id] ?? 0,
            'eligible' => $eligible,
            'ineligible_reason' => $eligible ? null : $this->ineligibleReason($member),
        ];
    }

    private function censusStatus(FamilyMember $member): string
    {
        if ($member->trashed()) {
            return 'deleted';
        }

        if ($member->status === 'migrated') {
            return 'transferred';
        }

        return $member->status;
    }

    private function isEligible(FamilyMember $member): bool
    {
        if ($member->trashed()) {
            return false;
        }

        return ! in_array($member->status, ['deceased', 'migrated'], true);
    }

    private function ineligibleReason(FamilyMember $member): string
    {
        if ($member->trashed()) {
            return 'Member record deleted';
        }

        if ($member->status === 'deceased') {
            return 'Deceased in parish records';
        }

        if ($member->status === 'migrated') {
            return 'Transferred from parish';
        }

        return 'Not eligible for enrollment';
    }

    private function photoUrl(FamilyMember $member): ?string
    {
        $family = $member->family;

        if ($family === null) {
            return null;
        }

        if (in_array(strtolower((string) $member->relationship_to_head), ['self', 'head'], true)) {
            return $family->head_profile_image_full_url ?: $family->profile_image_full_url;
        }

        return null;
    }
}
