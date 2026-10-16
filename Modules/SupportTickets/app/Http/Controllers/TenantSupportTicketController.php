<?php

namespace Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Http\Requests\StoreSupportTicketRequest;
use Modules\SupportTickets\Http\Requests\StoreTicketCommentRequest;
use Modules\SupportTickets\Http\Requests\CancelTicketRequest;
use Modules\SupportTickets\Http\Requests\ReopenTicketRequest;
use Modules\SupportTickets\Http\Requests\ResolveTenantTicketRequest;
use Modules\SupportTickets\Http\Resources\TenantTicketResource;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Models\SupportQueue;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Services\SupportTicketService;
use Modules\SupportTickets\Services\TicketAttachmentService;
use Modules\SupportTickets\Services\TicketCommentService;
use Modules\SupportTickets\Services\TicketParticipantService;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantSupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly TicketCommentService $comments,
        private readonly TicketAttachmentService $attachments,
        private readonly TicketParticipantService $participants,
    ) {}

    public function dashboard(): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenantId = $this->tenantId();
            $canViewAll = $user->hasPermission('support.tickets.view_all_tenant')
                || $user->isTenantAdmin();

            return response()->json([
                'success' => true,
                'data' => $this->tickets->dashboardForTenant($tenantId, $user, $canViewAll),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenantId = $this->tenantId();
            $canViewAll = $user->hasPermission('support.tickets.view_all_tenant')
                || $user->isTenantAdmin();

            $page = $this->tickets->paginateForTenant(
                $tenantId,
                $user,
                $request->only([
                    'q', 'status', 'priority', 'request_type_id', 'category_id',
                    'affected_module', 'date_from', 'date_to', 'scope', 'sort', 'direction',
                ]),
                (int) $request->input('per_page', 20),
                $canViewAll,
            );

            return response()->json([
                'success' => true,
                'data' => TenantTicketResource::collection($page->items()),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('create', SupportTicket::class);

            $ticket = $this->tickets->create($this->tenantId(), $user, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created.',
                'data' => new TenantTicketResource($ticket),
            ], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function show(string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket);
            $this->authorize('view', $found);

            return response()->json([
                'success' => true,
                'data' => new TenantTicketResource($found),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function comment(StoreTicketCommentRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('comment', $found);

            $comment = $this->comments->addPublicComment($found, $user, $request->validated('body'));

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'author' => $comment->author?->only(['id', 'name']),
                    'created_at' => $comment->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function addParticipant(Request $request, string $ticket): JsonResponse
    {
        try {
            $request->validate(['user_id' => ['required', 'integer']]);
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('manageParticipants', $found);

            $participant = $this->participants->add($found, $user, (int) $request->input('user_id'));

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $participant->user_id,
                    'name' => $participant->user?->name,
                ],
            ], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function removeParticipant(string $ticket, int $userId): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('manageParticipants', $found);

            $this->participants->remove($found, $user, $userId);

            return response()->json(['success' => true]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function uploadAttachment(Request $request, string $ticket): JsonResponse
    {
        try {
            $request->validate(['file' => ['required', 'file']]);
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('attach', $found);

            $attachment = $this->attachments->upload($found, $user, $request->file('file'));

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                ],
            ], 201);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function downloadAttachment(string $ticket, int $attachmentId)
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('view', $found);

            $attachment = $this->attachments->findForTicket($found, $attachmentId);

            return $this->attachments->streamDownload($attachment, $this->tenantId());
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function cancel(CancelTicketRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('cancel', $found);

            $updated = $this->tickets->transition($found, $user, TicketStatus::CANCELLED, $request->input('reason'));

            return response()->json([
                'success' => true,
                'data' => new TenantTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function resolve(ResolveTenantTicketRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('resolveTenant', $found);

            $summary = trim((string) $request->input('resolution_summary', ''));
            $updated = $this->tickets->resolveByTenant(
                $found,
                $user,
                $summary !== '' ? $summary : null,
            );

            return response()->json([
                'success' => true,
                'data' => new TenantTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function reopen(ReopenTicketRequest $request, string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('reopen', $found);

            $updated = $this->tickets->transition($found, $user, TicketStatus::IN_PROGRESS, $request->input('reason'));

            return response()->json([
                'success' => true,
                'data' => new TenantTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function confirmResolution(string $ticket): JsonResponse
    {
        try {
            $user = Auth::user();
            $found = $this->tickets->findForTenant($this->tenantId(), $ticket, false);
            $this->authorize('confirmResolution', $found);

            $updated = $this->tickets->transition($found, $user, TicketStatus::CLOSED);

            return response()->json([
                'success' => true,
                'data' => new TenantTicketResource($updated),
            ]);
        } catch (SupportTicketException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    public function lookups(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'request_types' => SupportRequestType::query()
                    ->where('active', true)
                    ->orderBy('sort_order')
                    ->with(['categories'])
                    ->get(['id', 'slug', 'name', 'requires_bug_fields']),
                'queues' => [],
            ],
        ]);
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireEffectiveTenantId();
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
