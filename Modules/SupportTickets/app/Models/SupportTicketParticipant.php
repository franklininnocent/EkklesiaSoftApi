<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class SupportTicketParticipant extends Model
{
    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'user_id',
        'added_by_user_id',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'added_by_user_id' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
