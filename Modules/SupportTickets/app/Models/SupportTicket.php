<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\SupportTickets\Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'tenant_id',
        'requester_user_id',
        'assigned_agent_id',
        'request_type_id',
        'category_id',
        'subcategory_id',
        'queue_id',
        'subject',
        'description',
        'steps_to_reproduce',
        'expected_result',
        'actual_result',
        'error_message',
        'bug_details',
        'business_impact',
        'affected_module',
        'occurrence_at',
        'priority',
        'status',
        'first_response_due_at',
        'resolution_due_at',
        'first_response_at',
        'resolved_at',
        'paused_at',
        'total_paused_seconds',
        'first_response_breached',
        'resolution_breached',
        'sla_warning_sent',
        'resolution_summary',
        'resolution_category',
        'root_cause',
        'workaround',
        'permanent_fix',
        'resolved_by_user_id',
        'resolved_by_actor',
        'reopen_allowed_until',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'requester_user_id' => 'integer',
        'assigned_agent_id' => 'integer',
        'resolved_by_user_id' => 'integer',
        'bug_details' => 'array',
        'occurrence_at' => 'datetime',
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'paused_at' => 'datetime',
        'reopen_allowed_until' => 'datetime',
        'total_paused_seconds' => 'integer',
        'first_response_breached' => 'boolean',
        'resolution_breached' => 'boolean',
        'sla_warning_sent' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(SupportRequestType::class, 'request_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'subcategory_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(SupportQueue::class, 'queue_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SupportTicketComment::class, 'ticket_id');
    }

    public function publicComments(): HasMany
    {
        return $this->comments()->where('is_internal', false)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'ticket_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SupportTicketParticipant::class, 'ticket_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id')->orderBy('created_at');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByTicketNumber(Builder $query, string $ticketNumber): Builder
    {
        return $query->where('ticket_number', strtoupper(trim($ticketNumber)));
    }

    public function isParticipant(int $userId): bool
    {
        return $this->participants()->where('user_id', $userId)->exists();
    }

    public static function formatTicketNumber(int $id): string
    {
        return 'ES-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    protected static function newFactory(): SupportTicketFactory
    {
        return SupportTicketFactory::new();
    }
}
