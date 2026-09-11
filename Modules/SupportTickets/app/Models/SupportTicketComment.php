<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class SupportTicketComment extends Model
{
    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'author_user_id',
        'is_internal',
        'body',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'tenant_id' => 'integer',
        'author_user_id' => 'integer',
        'is_internal' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
