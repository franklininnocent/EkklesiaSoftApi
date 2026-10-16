<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Http\Requests\Admin\ApproveUpgradeRequest;
use Modules\Subscriptions\Http\Requests\Admin\ReviewNoteRequest;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Services\UpgradeRequestService;

/**
 * Platform review queue for church plan requests (subscriptions.requests.review).
 */
class UpgradeRequestController extends Controller
{
    public function __construct(private readonly UpgradeRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'PENDING', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED', 'ALL'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'OPEN';

        $query = SubscriptionUpgradeRequest::query()
            ->with(['tenant', 'requestedPlan', 'currentPlan', 'requester', 'reviewer'])
            ->orderByDesc('id');
        if ($status === 'OPEN') {
            $query->whereIn('status', SubscriptionUpgradeRequest::OPEN_STATUSES);
        } elseif ($status !== 'ALL') {
            $query->where('status', $status);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (SubscriptionUpgradeRequest $r) => $this->requests->present($r, true))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'open_count' => SubscriptionUpgradeRequest::query()->whereIn('status', SubscriptionUpgradeRequest::OPEN_STATUSES)->count(),
            ],
        ]);
    }

    public function approve(ApproveUpgradeRequest $request, int $upgradeRequest): JsonResponse
    {
        $model = SubscriptionUpgradeRequest::query()->findOrFail($upgradeRequest);
        $approved = $this->requests->approve($model, $request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => 'Request approved and plan updated.', 'data' => $this->requests->present($approved, true)]);
    }

    public function reject(ReviewNoteRequest $request, int $upgradeRequest): JsonResponse
    {
        $model = SubscriptionUpgradeRequest::query()->findOrFail($upgradeRequest);
        $rejected = $this->requests->reject($model, $request->user(), (string) $request->validated('note'));

        return response()->json(['success' => true, 'message' => 'Request declined.', 'data' => $this->requests->present($rejected, true)]);
    }

    public function requestInfo(ReviewNoteRequest $request, int $upgradeRequest): JsonResponse
    {
        $model = SubscriptionUpgradeRequest::query()->findOrFail($upgradeRequest);
        $updated = $this->requests->requestInfo($model, $request->user(), (string) $request->validated('note'));

        return response()->json(['success' => true, 'message' => 'Asked the church for more information.', 'data' => $this->requests->present($updated, true)]);
    }
}
