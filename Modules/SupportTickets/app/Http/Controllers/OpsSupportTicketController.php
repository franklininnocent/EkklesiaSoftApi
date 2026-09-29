<?php

namespace Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Http\Requests\CancelTicketRequest;
use Modules\SupportTickets\Http\Requests\ReopenTicketRequest;
use Modules\SupportTickets\Http\Requests\StoreTicketCommentRequest;
use Modules\SupportTickets\Http\Resources\OpsTicketResource;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Services\SupportTicketService;
use Modules\SupportTickets\Services\TicketAttachmentService;
use Modules\SupportTickets\Services\TicketCommentService;
use Modules\SupportTickets\Support\TenantResolutionCategory;
use Modules\SupportTickets\Support\TicketPriority;
use Modules\SupportTickets\Support\TicketStatus;

class OpsSupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly TicketCommentService $comments,
        private readonly TicketAttachmentService $attachments,
    ) {}

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->tickets->dashboardForOps(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->tickets->paginateForOps(
            $request->only([
                'q', 'status', 'priority', 'request_type_id', 'category_id',
                'affected_module', 'date_from', 'date_to', 'tenant_id', 'sort', 'direction',
            ]),
            (int) $request->input('per_page', 20),
        );

        return response()->json([
            'success' => true,
            'data' => OpsTicketResource::collection($page->items()),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function show(string $ticket): JsonResponse
    {
        try {
            $found = $this->tickets->findForOps($ticket);
            $this->authorize('view', $found);
            $found->load(['comments.author:id,name']);

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($found),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function assign(Request $request, string $ticket): JsonResponse
    {
        try {
            $request->validate([
                'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
                'queue_id' => ['nullable', 'integer', 'exists:support_queues,id'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ]);

            $user = Auth::user();
            $this->authorize('assign', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $updated = $this->tickets->assign(
                $found,
                $user,
                $request->filled('assigned_agent_id') ? (int) $request->input('assigned_agent_id') : null,
                $request->filled('queue_id') ? (int) $request->input('queue_id') : null,
                $request->input('reason'),
            );

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function startProgress(Request $request, string $ticket): JsonResponse
    {
        return $this->transitionTo($ticket, TicketStatus::IN_PROGRESS, $request->input('reason'));
    }

    public function awaitingYou(Request $request, string $ticket): JsonResponse
    {
        return $this->transitionTo($ticket, TicketStatus::AWAITING_YOU, $request->input('reason'));
    }

    public function awaitingEkklesia(Request $request, string $ticket): JsonResponse
    {
        return $this->transitionTo($ticket, TicketStatus::AWAITING_EKKLESIA, $request->input('reason'));
    }

    public function close(Request $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('close', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            if ($found->status !== TicketStatus::RESOLVED) {
                throw new SupportTicketException('Only resolved tickets can be closed from ops.', 422);
            }

            $updated = $this->tickets->transition($found, $user, TicketStatus::CLOSED, $request->input('reason'));

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function reopen(ReopenTicketRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('reopen', $found);

            $updated = $this->tickets->transition($found, $user, TicketStatus::IN_PROGRESS, $request->input('reason'));

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function cancel(CancelTicketRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('cancelOps', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $updated = $this->tickets->transition($found, $user, TicketStatus::CANCELLED, $request->input('reason'));

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function changePriority(Request $request, string $ticket): JsonResponse
    {
        try {
            $request->validate([
                'priority' => ['required', 'string', Rule::in(TicketPriority::all())],
            ]);

            $user = Auth::user();
            $this->authorize('changePriority', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $updated = $this->tickets->changePriority($found, $user, $request->input('priority'));

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function comment(StoreTicketCommentRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('comment', $found);

            $comment = $this->comments->addPublicComment($found, $user, $request->validated('body'));

            return response()->json(['success' => true, 'data' => $comment], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function internalNote(StoreTicketCommentRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('internalNote', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $comment = $this->comments->addInternalNote($found, $user, $request->validated('body'));

            return response()->json(['success' => true, 'data' => $comment], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function resolve(Request $request, string $ticket): JsonResponse
    {
        try {
            $request->validate([
                'resolution_summary' => ['required', 'string', 'max:10000'],
                'resolution_category' => ['nullable', 'string', 'max:64'],
                'root_cause' => ['nullable', 'string', 'max:5000'],
                'workaround' => ['nullable', 'string', 'max:5000'],
                'permanent_fix' => ['nullable', 'string', 'max:5000'],
            ]);

            $user = Auth::user();
            $this->authorize('resolve', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $found->resolution_summary = trim(strip_tags((string) $request->input('resolution_summary')));
            $found->resolution_category = $request->input('resolution_category')
                ?: TenantResolutionCategory::EKKLESIA_RESOLVED;
            $found->root_cause = $request->input('root_cause');
            $found->workaround = $request->input('workaround');
            $found->permanent_fix = $request->input('permanent_fix');
            $found->save();

            $updated = $this->tickets->transition($found, $user, TicketStatus::RESOLVED);

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function downloadAttachment(string $ticket, int $attachmentId)
    {
        try {
            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $attachment = $this->attachments->findForTicket($found, $attachmentId);

            return $this->attachments->streamDownload($attachment, (int) $found->tenant_id);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    private function transitionTo(string $ticket, string $status, ?string $reason = null): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('changeStatus', SupportTicket::class);

            $found = $this->tickets->findForOps($ticket, false);
            $this->authorize('view', $found);

            $updated = $this->tickets->transition($found, $user, $status, $reason);

            return response()->json([
                'success' => true,
                'data' => new OpsTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
