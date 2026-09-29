<?php

namespace Modules\SupportTickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupportTickets\Models\SupportTicket;

/** @mixin SupportTicket */
class OpsTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = (new TenantTicketResource($this->resource))->toArray($request);

        return array_merge($base, [
            'tenant' => $this->tenant?->only(['id', 'name']),
            'queue' => $this->queue?->only(['id', 'slug', 'name']),
            'assigned_agent' => $this->assignedAgent?->only(['id', 'name']),
            'internal_comments' => $this->whenLoaded('comments', function () {
                return $this->comments
                    ->where('is_internal', true)
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'body' => $c->body,
                        'author' => $c->author?->only(['id', 'name']),
                        'created_at' => $c->created_at?->toIso8601String(),
                    ]);
            }),
            'comments' => $this->whenLoaded('comments', function () {
                return $this->comments
                    ->where('is_internal', false)
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'body' => $c->body,
                        'author' => $c->author?->only(['id', 'name']),
                        'created_at' => $c->created_at?->toIso8601String(),
                    ]);
            }),
            'resolution_category' => $this->resolution_category,
            'root_cause' => $this->root_cause,
            'workaround' => $this->workaround,
            'permanent_fix' => $this->permanent_fix,
        ]);
    }
}
