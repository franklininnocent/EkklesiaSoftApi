<?php

namespace Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Authentication\Models\User;
use Modules\Notifications\Http\Requests\BulkNotificationActionRequest;
use Modules\Notifications\Http\Requests\UpdateNotificationPreferencesRequest;
use Modules\Notifications\Http\Resources\UserNotificationResource;
use Modules\Notifications\Services\DeepLinkAuthorizer;
use Modules\Notifications\Services\InboxContextResolver;
use Modules\Notifications\Services\InboxQueryService;
use Modules\Notifications\Services\InboxStateService;
use Modules\Notifications\Services\NotificationPreferenceService;
use Modules\Notifications\Services\UnreadCounter;
use Modules\Tenants\Support\ApiPagination;

class NotificationInboxController extends Controller
{
    public function __construct(
        private readonly InboxContextResolver $inboxContext,
        private readonly InboxQueryService $query,
        private readonly InboxStateService $state,
        private readonly UnreadCounter $unreadCounter,
        private readonly DeepLinkAuthorizer $deepLink,
        private readonly NotificationPreferenceService $preferences,
    ) {
    }

    public function index(Request $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $perPage = min(50, ApiPagination::clampFromRequest($request, 20));
        $result = $this->query->list($context, $request->query(), $perPage);

        return response()->json([
            'success' => true,
            'data' => UserNotificationResource::collection($result['data']),
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'has_more' => $result['has_more'],
                'per_page' => $result['per_page'],
            ],
        ]);
    }

    public function unreadCount(Request $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $this->unreadCounter->count($context)],
        ]);
    }

    public function show(Request $request, string $audience, string $id): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $row = $this->query->findForUser($context, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $link = $this->deepLink->resolve($user, $row->event);

        return response()->json([
            'success' => true,
            'data' => array_merge(
                (new UserNotificationResource($row))->resolve(),
                [
                    'deep_link' => $link['route'] !== '' ? ['route' => $link['route'], 'params' => $link['params']] : null,
                    'subject_status' => $link['subject_status'],
                ]
            ),
        ]);
    }

    public function open(Request $request, string $audience, string $id): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $row = $this->query->findForUser($context, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $link = $this->deepLink->resolve($user, $row->event);
        if ($link['subject_status'] !== 'available') {
            return response()->json(['success' => false, 'message' => 'You do not have access to this record.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => ['route' => $link['route'], 'params' => $link['params']],
        ]);
    }

    public function markRead(Request $request, string $audience, string $id): JsonResponse
    {
        return $this->stateResponse($audience, fn ($ctx) => $this->state->markRead($ctx, $id));
    }

    public function markUnread(Request $request, string $audience, string $id): JsonResponse
    {
        return $this->stateResponse($audience, fn ($ctx) => $this->state->markUnread($ctx, $id));
    }

    public function archive(Request $request, string $audience, string $id): JsonResponse
    {
        return $this->stateResponse($audience, fn ($ctx) => $this->state->archive($ctx, $id));
    }

    public function restore(Request $request, string $audience, string $id): JsonResponse
    {
        return $this->stateResponse($audience, fn ($ctx) => $this->state->restore($ctx, $id));
    }

    public function markAllRead(Request $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $result = $this->state->markAllRead($context);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function bulkAction(BulkNotificationActionRequest $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $count = $this->state->bulkAction(
            $context,
            $request->validated('action'),
            $request->validated('ids'),
        );

        return response()->json(['success' => true, 'data' => ['affected' => $count]]);
    }

    public function preferences(Request $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        return response()->json([
            'success' => true,
            'data' => $this->preferences->listForUser((int) $user->id)->values(),
        ]);
    }

    public function updatePreferences(UpdateNotificationPreferencesRequest $request, string $audience): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        $this->preferences->updateForUser((int) $user->id, $request->validated('preferences'));

        return response()->json(['success' => true, 'message' => 'Preferences saved.']);
    }

    private function stateResponse(string $audience, callable $action): JsonResponse
    {
        $user = $this->user();
        $context = $this->inboxContext->forUser($user);
        $this->inboxContext->assertRouteMatches($context, $audience);

        try {
            $row = $action($context);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new UserNotificationResource($row),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
