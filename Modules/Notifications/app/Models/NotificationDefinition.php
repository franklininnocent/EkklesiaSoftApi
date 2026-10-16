<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'event_type', 'category', 'module', 'title_template', 'body_template',
        'channels', 'priority', 'mandatory', 'notify_actor', 'recipient_strategy',
        'collapse_mode', 'allows_delete', 'active',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'mandatory' => 'boolean',
            'notify_actor' => 'boolean',
            'allows_delete' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
