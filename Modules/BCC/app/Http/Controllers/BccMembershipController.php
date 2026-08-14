<?php

namespace Modules\BCC\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Http\Controllers\Concerns\RespondsToBccDomain;
use Modules\BCC\Http\Requests\AssignBccFamiliesRequest;
use Modules\BCC\Http\Requests\IndexBccMembersRequest;
use Modules\BCC\Http\Requests\IndexBccPeopleRequest;
use Modules\BCC\Http\Requests\LookupBccFamiliesRequest;
use Modules\BCC\Http\Requests\RemoveBccMembershipRequest;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Services\BccFamilyMembershipService;

class BccMembershipController extends Controller
{
    use AuthorizesRequests;
    use RespondsToBccDomain;

    public function __construct(private readonly BccFamilyMembershipService $memberships)
    {
    }

    public function index(IndexBccMembersRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BccFamilyMembership::class);

        try {
            $paginator = $this->memberships->paginateMembers(
                $this->tenantId(),
                $bccId,
                $request->validated(),
                (int) ($request->validated()['per_page'] ?? 15),
            );
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return $this->paginated($paginator, fn (BccFamilyMembership $row) => $this->presentMembership($row));
    }

    public function store(AssignBccFamiliesRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('create', BccFamilyMembership::class);
        $payload = $request->validated();

        try {
            $result = $this->memberships->assignFamilies(
                $this->tenantId(),
                $bccId,
                $payload['family_ids'],
                (bool) ($payload['transfer'] ?? false),
                $payload['joined_date'] ?? null,
            );
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Families assigned to this BCC.',
            'data' => $result,
        ], 201);
    }

    public function destroy(RemoveBccMembershipRequest $request, string $bccId, string $membershipId): JsonResponse
    {
        $this->authorize('create', BccFamilyMembership::class);
        $payload = $request->validated();

        try {
            $membership = $this->memberships->removeMembership(
                $this->tenantId(),
                $bccId,
                $membershipId,
                $payload['exit_date'] ?? null,
                $payload['exit_reason'] ?? null,
            );
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Family removed from this BCC.',
            'data' => $this->presentMembership($membership),
        ]);
    }

    public function people(IndexBccPeopleRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BccFamilyMembership::class);

        try {
            $paginator = $this->memberships->paginatePeople(
                $this->tenantId(),
                $bccId,
                $request->validated(),
                (int) ($request->validated()['per_page'] ?? 15),
            );
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return $this->paginated($paginator, function ($member) {
            return [
                'id' => $member->id,
                'person_id' => $member->person_id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'display_name' => $member->full_name_display,
                'gender' => $member->gender,
                'date_of_birth' => $member->date_of_birth?->toDateString(),
                'age' => $member->age,
                'status' => $member->status,
                'relationship_to_head' => $member->relationship_to_head,
                'family_id' => $member->family_id,
                'family_name' => $member->family?->family_name,
                'family_code' => $member->family?->family_code,
            ];
        });
    }

    public function lookup(LookupBccFamiliesRequest $request): JsonResponse
    {
        $this->authorize('create', BccFamilyMembership::class);
        $payload = $request->validated();

        $paginator = $this->memberships->lookupFamilies(
            $this->tenantId(),
            $payload['search'] ?? '',
            (int) ($payload['per_page'] ?? 20),
            $payload['exclude_bcc_id'] ?? null,
        );

        return $this->paginated($paginator, function ($family) {
            return [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'family_code' => $family->family_code,
                'head_of_family' => $family->head_of_family,
                'status' => $family->status,
                'member_count' => $family->members_count,
                'current_bcc_id' => $family->bcc_id,
                'current_bcc_name' => $family->bcc?->name,
                'eligible' => $family->bcc_id === null,
            ];
        });
    }

    public function history(Request $request, string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BccFamilyMembership::class);
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));

        try {
            $paginator = $this->memberships->paginateHistory($this->tenantId(), $bccId, $perPage);
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return $this->paginated($paginator, fn (BccFamilyMembership $row) => $this->presentMembership($row));
    }

    private function presentMembership(BccFamilyMembership $row): array
    {
        return [
            'id' => $row->id,
            'bcc_id' => $row->bcc_id,
            'family_id' => $row->family_id,
            'status' => $row->status,
            'is_current' => $row->is_current,
            'joined_date' => $row->joined_date?->toDateString(),
            'exit_date' => $row->exit_date?->toDateString(),
            'exit_reason' => $row->exit_reason,
            'family' => $row->family ? [
                'id' => $row->family->id,
                'family_name' => $row->family->family_name,
                'family_code' => $row->family->family_code,
                'status' => $row->family->status,
                'member_count' => $row->family->members_count ?? $row->family->members()->count(),
            ] : null,
            'created_by_name' => $row->creator?->name,
        ];
    }

    private function paginated($paginator, callable $map): JsonResponse
    {
        $items = collect($paginator->items())->map($map)->values()->all();

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
}
