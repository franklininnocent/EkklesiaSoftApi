<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Model;

class SupportQueue extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
