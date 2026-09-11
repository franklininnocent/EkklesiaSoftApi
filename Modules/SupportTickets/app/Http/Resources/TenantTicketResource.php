<?php

namespace Modules\SupportTickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TenantResolutionCategory;

/** @mixin SupportTicket */
class TenantTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->when($this->relationLoaded('publicComments') || $request->query('detail') === '1', $this->description),
            'request_type' => $this->requestType?->only(['id', 'slug', 'name']),
            'category' => $this->category?->only(['id', 'name']),
            'subcategory' => $this->subcategory?->only(['id', 'name']),
            'priority' => $this->priority,
            'status' => $this->status,
            'business_impact' => $this->when($this->relationLoaded('publicComments'), $this->business_impact),
            'affected_module' => $this->affected_module,
            'occurrence_at' => $this->occurrence_at?->toIso8601String(),
            'steps_to_reproduce' => $this->when($this->relationLoaded('publicComments'), $this->steps_to_reproduce),
            'expected_result' => $this->when($this->relationLoaded('publicComments'), $this->expected_result),
            'actual_result' => $this->when($this->relationLoaded('publicComments'), $this->actual_result),
            'error_message' => $this->when($this->relationLoaded('publicComments'), $this->error_message),
            'bug_details' => $this->when($this->relationLoaded('publicComments'), $this->bug_details),
            'requester' => $this->requester?->only(['id', 'name']),
            'sla' => [
                'first_response_due_at' => $this->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
                'first_response_at' => $this->first_response_at?->toIso8601String(),
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'first_response_breached' => $this->first_response_breached,
                'resolution_breached' => $this->resolution_breached,
                'at_risk' => $this->isSlaAtRisk(),
            ],
            'resolution_summary' => $this->when(
                in_array($this->status, ['resolved', 'closed'], true),
                $this->resolution_summary
            ),
            'resolution_category' => $this->when(
                in_array($this->status, ['resolved', 'closed'], true),
                $this->resolution_category
            ),
            'resolution_method' => $this->when(
                in_array($this->status, ['resolved', 'closed'], true) && $this->resolution_category,
                fn () => TenantResolutionCategory::label((string) $this->resolution_category)
            ),
            'reopen_allowed_until' => $this->reopen_allowed_until?->toIso8601String(),
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_by_actor' => $this->resolved_by_actor,
            'resolved_by' => $this->resolvedBy?->only(['id', 'name']),
            'comments' => $this->whenLoaded('publicComments', function () {
                return $this->publicComments->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'author' => $c->author?->only(['id', 'name']),
                    'created_at' => $c->created_at?->toIso8601String(),
                ]);
            }),
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'original_name' => $a->original_name,
                    'mime_type' => $a->mime_type,
                    'size_bytes' => $a->size_bytes,
                    'created_at' => $a->created_at?->toIso8601String(),
                ]);
            }),
            'participants' => $this->whenLoaded('participants', function () {
                return $this->participants->map(fn ($p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name,
                ]);
            }),
            'activity' => $this->whenLoaded('events', function () {
                return $this->events
                    ->whereNotIn('event_type', ['internal_note'])
                    ->map(fn ($e) => [
                        'event_type' => $e->event_type,
                        'old_value' => $e->old_value,
                        'new_value' => $e->new_value,
                        'reason' => $e->reason,
                        'actor' => $e->actor?->only(['id', 'name']),
                        'created_at' => $e->created_at?->toIso8601String(),
                    ]);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function isSlaAtRisk(): bool
    {
        if (in_array($this->status, ['resolved', 'closed', 'cancelled'], true)) {
            return false;
        }

        $threshold = now()->addHour();

        return ($this->first_response_due_at && $this->first_response_due_at->lte($threshold))
            || ($this->resolution_due_at && $this->resolution_due_at->lte($threshold));
    }
}
