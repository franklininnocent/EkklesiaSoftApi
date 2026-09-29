<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class SupportTicketEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'actor_user_id',
        'support_session_id',
        'event_type',
        'old_value',
        'new_value',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'tenant_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
