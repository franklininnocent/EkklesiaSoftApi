<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;

class SupportSlaPolicy extends Model
{
    protected $fillable = [
        'priority',
        'first_response_minutes',
        'resolution_minutes',
    ];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
    ];
}
