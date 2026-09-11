<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Http\Requests\ListApplicationAccessSessionsRequest;
use Modules\ApplicationAccess\Http\Requests\SessionTimelineRequest;
use Modules\ApplicationAccess\Http\Resources\ApplicationAccessEventResource;
use Modules\ApplicationAccess\Http\Resources\ApplicationAccessSessionResource;
use Modules\ApplicationAccess\Services\ApplicationAccessEventQueryService;
use Modules\ApplicationAccess\Services\ApplicationAccessInvestigationAudit;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionQueryService;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionRevokeService;
use Modules\Authentication\Models\User;
use RuntimeException;

class ApplicationAccessSessionController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessSessionQueryService $sessions,
        private readonly ApplicationAccessEventQueryService $events,
        private readonly ApplicationAccessInvestigationAudit $investigationAudit,
        private readonly ApplicationAccessSessionRevokeService $revokeService,
    ) {}

    public function index(ListApplicationAccessSessionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paginator = $this->sessions->paginate(
            $request->filters(),
            $request->resolvedPage($validated),
            $request->resolvedPerPage($validated),
        );

        return response()->json([
            'success' => true,
            'data' => ApplicationAccessSessionResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
            ], 404);
        }

        $actor = request()->user();
        if ($actor instanceof User) {
            $this->investigationAudit->recordSessionOpen($actor, $session);
        }

        return response()->json([
            'success' => true,
            'data' => new ApplicationAccessSessionResource($session),
        ]);
    }

    public function revoke(string $id): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
            ], 404);
        }

        $actor = request()->user();
        if (! $actor instanceof User) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        try {
            $result = $this->revokeService->revoke($session, $actor);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new ApplicationAccessSessionResource($result['session']),
            'revoked_self' => $result['revoked_self'],
            'message' => $result['revoked_self']
                ? 'Your session was signed out successfully.'
                : 'Session revoked successfully.',
        ]);
    }

    public function timeline(string $id, SessionTimelineRequest $request): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
            ], 404);
        }

        $validated = $request->validated();
        $result = $this->events->timelineForSession(
            $id,
            $validated,
            $request->resolvedPerPage($validated, 50, 50),
        );

        return response()->json([
            'success' => true,
            'data' => ApplicationAccessEventResource::collection($result['data']),
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'has_more' => $result['has_more'],
                'per_page' => $result['per_page'],
            ],
        ]);
    }
}
