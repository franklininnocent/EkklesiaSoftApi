<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Http\Requests\IndexGuestMemberRequest;
use Modules\MinistriesAssociations\Http\Requests\LinkGuestMemberParishionerRequest;
use Modules\MinistriesAssociations\Http\Requests\StoreGuestMemberRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateGuestMemberRequest;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class GuestMemberController extends Controller
{
    use AuthorizesRequests;

    /** @var list<string> */
    private const INELIGIBLE_FAMILY_STATUSES = ['deceased', 'migrated'];

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function index(IndexGuestMemberRequest $request): JsonResponse
    {
        $this->authorize('viewAny', GuestMember::class);

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $query = GuestMember::query()
            ->forTenant($tenantId)
            ->with('linkedFamilyMember.family')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('first_name', 'ilike', $search)
                    ->orWhere('last_name', 'ilike', $search)
                    ->orWhere('phone', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search)
                    ->orWhere('external_organization', 'ilike', $search);
            });
        }

        if (! empty($validated['guest_type'])) {
            $query->where('guest_type', $validated['guest_type']);
        }

        if (array_key_exists('has_linked_parishioner', $validated)) {
            if ((bool) $validated['has_linked_parishioner']) {
                $query->whereNotNull('linked_family_member_id');
            } else {
                $query->whereNull('linked_family_member_id');
            }
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (GuestMember $guestMember) => $this->presentGuestMember($guestMember))
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

    public function store(StoreGuestMemberRequest $request): JsonResponse
    {
        $this->authorize('create', GuestMember::class);

        $tenantId = $this->tenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['guest_type'] = $payload['guest_type'] ?? GuestMember::GUEST_TYPE_SUPPORTER;

        $guestMember = GuestMember::create($payload);

        $this->auditService->log(
            $tenantId,
            'guest_member.created',
            'guest_member',
            $guestMember->id,
            null,
            $this->auditSnapshot($guestMember),
        );

        return response()->json([
            'success' => true,
            'message' => 'Guest member created successfully.',
            'data' => $this->presentGuestMember($guestMember),
        ], 201);
    }

    public function show(string $guestMemberId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $guestMember = $this->findGuestMember($tenantId, $guestMemberId);

        $this->authorize('view', $guestMember);

        $guestMember->load('linkedFamilyMember.family');

        return response()->json([
            'success' => true,
            'data' => $this->presentGuestMember($guestMember),
        ]);
    }

    public function update(UpdateGuestMemberRequest $request, string $guestMemberId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $guestMember = $this->findGuestMember($tenantId, $guestMemberId);

        $this->authorize('update', $guestMember);

        $payload = $request->validated();
        $mergedContact = [
            'phone' => array_key_exists('phone', $payload) ? $payload['phone'] : $guestMember->phone,
            'email' => array_key_exists('email', $payload) ? $payload['email'] : $guestMember->email,
        ];

        if (empty($mergedContact['phone']) && empty($mergedContact['email'])) {
            return response()->json([
                'success' => false,
                'message' => 'At least one contact method (phone or email) is required.',
                'errors' => [
                    'phone' => ['Provide a phone number or email address.'],
                    'email' => ['Provide a phone number or email address.'],
                ],
            ], 422);
        }

        $oldValues = $this->auditSnapshot($guestMember);
        $guestMember->update($payload);
        $guestMember->refresh()->load('linkedFamilyMember.family');

        $this->auditService->log(
            $tenantId,
            'guest_member.updated',
            'guest_member',
            $guestMember->id,
            $oldValues,
            $this->auditSnapshot($guestMember),
        );

        return response()->json([
            'success' => true,
            'message' => 'Guest member updated successfully.',
            'data' => $this->presentGuestMember($guestMember),
        ]);
    }

    public function linkParishioner(LinkGuestMemberParishionerRequest $request, string $guestMemberId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $guestMember = $this->findGuestMember($tenantId, $guestMemberId);

        $this->authorize('linkParishioner', $guestMember);

        $familyMemberId = $request->validated('family_member_id');

        if ($guestMember->linked_family_member_id === $familyMemberId) {
            $guestMember->load('linkedFamilyMember.family');

            return response()->json([
                'success' => true,
                'message' => 'Guest member is already linked to this parishioner.',
                'data' => $this->presentGuestMember($guestMember),
            ]);
        }

        if ($guestMember->linked_family_member_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Guest member is already linked to a parishioner.',
                'errors' => [
                    'linked_family_member_id' => ['Unlink is not supported. Guest is already linked.'],
                ],
            ], 422);
        }

        $eligibility = $this->parishMemberEligibility($tenantId, $familyMemberId);
        if ($eligibility !== null) {
            return $eligibility;
        }

        $existingLink = GuestMember::query()
            ->forTenant($tenantId)
            ->where('linked_family_member_id', $familyMemberId)
            ->where('id', '!=', $guestMember->id)
            ->exists();

        if ($existingLink) {
            return response()->json([
                'success' => false,
                'message' => 'Parishioner is already linked to another guest record.',
                'errors' => [
                    'family_member_id' => ['This parishioner is already linked to another guest.'],
                ],
            ], 422);
        }

        $oldValues = $this->auditSnapshot($guestMember);
        $guestMember->update(['linked_family_member_id' => $familyMemberId]);
        $guestMember->refresh()->load('linkedFamilyMember.family');

        $this->auditService->log(
            $tenantId,
            'guest_member.linked_to_parishioner',
            'guest_member',
            $guestMember->id,
            $oldValues,
            $this->auditSnapshot($guestMember),
        );

        return response()->json([
            'success' => true,
            'message' => 'Guest member linked to parishioner successfully.',
            'data' => $this->presentGuestMember($guestMember),
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findGuestMember(int $tenantId, string $guestMemberId): GuestMember
    {
        return GuestMember::query()
            ->forTenant($tenantId)
            ->findOrFail($guestMemberId);
    }

    private function parishMemberEligibility(int $tenantId, string $familyMemberId): ?JsonResponse
    {
        $familyMember = FamilyMember::query()
            ->whereHas('family', fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))
            ->find($familyMemberId);

        if ($familyMember === null) {
            return response()->json([
                'success' => false,
                'message' => 'Parish member not found.',
                'errors' => [
                    'family_member_id' => ['Parish member not found.'],
                ],
            ], 422);
        }

        if (in_array($familyMember->status, self::INELIGIBLE_FAMILY_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Parish member is not eligible to be linked.',
                'errors' => [
                    'family_member_id' => ['Member is deceased or transferred in parish records.'],
                ],
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGuestMember(GuestMember $guestMember): array
    {
        $linkedFamilyMember = $guestMember->relationLoaded('linkedFamilyMember')
            ? $guestMember->linkedFamilyMember
            : null;

        return [
            'id' => $guestMember->id,
            'first_name' => $guestMember->first_name,
            'last_name' => $guestMember->last_name,
            'display_name' => $guestMember->display_name,
            'gender' => $guestMember->gender,
            'phone' => $guestMember->phone,
            'email' => $guestMember->email,
            'address' => $guestMember->address,
            'guest_type' => $guestMember->guest_type,
            'external_organization' => $guestMember->external_organization,
            'support_type' => $guestMember->support_type,
            'remarks' => $guestMember->remarks,
            'linked_family_member_id' => $guestMember->linked_family_member_id,
            'linked_parishioner' => $linkedFamilyMember ? [
                'id' => $linkedFamilyMember->id,
                'display_name' => $linkedFamilyMember->full_name_display,
                'family_name' => $linkedFamilyMember->family?->family_name,
            ] : null,
            'created_at' => $guestMember->created_at?->toIso8601String(),
            'updated_at' => $guestMember->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(GuestMember $guestMember): array
    {
        return [
            'id' => $guestMember->id,
            'first_name' => $guestMember->first_name,
            'last_name' => $guestMember->last_name,
            'guest_type' => $guestMember->guest_type,
            'phone' => $guestMember->phone,
            'email' => $guestMember->email,
            'linked_family_member_id' => $guestMember->linked_family_member_id,
        ];
    }
}
