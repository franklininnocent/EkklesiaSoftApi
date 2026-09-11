<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'uploaded_by_user_id',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'tenant_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
