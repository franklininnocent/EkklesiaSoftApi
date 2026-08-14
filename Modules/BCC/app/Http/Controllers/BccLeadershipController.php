<?php

namespace Modules\BCC\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Http\Controllers\Concerns\RespondsToBccDomain;
use Modules\BCC\Http\Requests\AssignBccLeadershipRequest;
use Modules\BCC\Http\Requests\HandoverBccLeadershipRequest;
use Modules\BCC\Http\Requests\IndexBccLeadershipRequest;
use Modules\BCC\Http\Requests\TerminateBccLeadershipRequest;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Services\BccLeadershipService;

class BccLeadershipController extends Controller
{
    use AuthorizesRequests;
    use RespondsToBccDomain;

    public function __construct(private readonly BccLeadershipService $leadership)
    {
    }

    public function current(string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BCCLeader::class);

        try {
            $data = $this->leadership->current($this->tenantId(), $bccId);
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'active_count' => $data['active_count'],
                'primary_leader' => $data['primary_leader']
                    ? $this->presentLeader($data['primary_leader'])
                    : null,
                'leaders' => collect($data['leaders'])->map(fn (BCCLeader $l) => $this->presentLeader($l))->values(),
            ],
        ]);
    }

    public function timeline(IndexBccLeadershipRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BCCLeader::class);
        $payload = $request->validated();

        try {
            $paginator = $this->leadership->timeline(
                $this->tenantId(),
                $bccId,
                $payload,
                (int) ($payload['per_page'] ?? 15),
            );
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (BCCLeader $l) => $this->presentLeader($l))->values(),
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

    public function eligible(string $bccId): JsonResponse
    {
        $this->authorize('assign', BCCLeader::class);

        try {
            $members = $this->leadership->eligibleMembers($this->tenantId(), $bccId);
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'data' => $members->map(fn ($m) => [
                'id' => $m->id,
                'display_name' => $m->full_name_display,
                'family_id' => $m->family_id,
                'family_name' => $m->family?->family_name,
                'family_code' => $m->family?->family_code,
            ])->values(),
        ]);
    }

    public function assign(AssignBccLeadershipRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('assign', BCCLeader::class);

        try {
            $leader = $this->leadership->assign($this->tenantId(), $bccId, $request->validated());
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leader assigned.',
            'data' => $this->presentLeader($leader),
        ], 201);
    }

    public function terminate(TerminateBccLeadershipRequest $request, string $bccId, string $leaderId): JsonResponse
    {
        $this->authorize('assign', BCCLeader::class);

        try {
            $leader = $this->leadership->terminate($this->tenantId(), $bccId, $leaderId, $request->validated());
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leadership ended.',
            'data' => $this->presentLeader($leader),
        ]);
    }

    public function handover(HandoverBccLeadershipRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('assign', BCCLeader::class);

        try {
            $result = $this->leadership->handover($this->tenantId(), $bccId, $request->validated());
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leadership handed over.',
            'data' => [
                'outgoing' => $this->presentLeader($result['outgoing']),
                'incoming' => $this->presentLeader($result['incoming']),
            ],
        ]);
    }

    private function presentLeader(BCCLeader $leader): array
    {
        return [
            'id' => $leader->id,
            'bcc_id' => $leader->bcc_id,
            'family_member_id' => $leader->family_member_id,
            'role' => $leader->role,
            'role_description' => $leader->role_description,
            'appointed_date' => $leader->appointed_date?->toDateString(),
            'appointment_date' => $leader->appointed_date?->toDateString(),
            'term_start_date' => $leader->term_start_date?->toDateString(),
            'effective_from' => $leader->term_start_date?->toDateString(),
            'term_end_date' => $leader->term_end_date?->toDateString(),
            'effective_to' => $leader->term_end_date?->toDateString(),
            'term_label' => $leader->term_label,
            'appointment_reference' => $leader->appointment_reference,
            'is_interim' => $leader->is_interim,
            'is_active' => $leader->is_active,
            'status' => $leader->status,
            'exit_reason' => $leader->exit_reason,
            'responsibilities' => $leader->responsibilities,
            'notes' => $leader->notes,
            'remarks' => $leader->remarks,
            'member_name' => $leader->member?->full_name_display,
            'family_name' => $leader->member?->family?->family_name,
        ];
    }
}
