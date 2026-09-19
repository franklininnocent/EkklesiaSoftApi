<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'definition_id', 'event_type', 'category', 'module', 'priority', 'inbox_scope',
        'tenant_id', 'actor_user_id', 'actor_display_name', 'subject_type', 'subject_id',
        'title', 'body', 'data', 'idempotency_key', 'collapse_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(NotificationDefinition::class, 'definition_id');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'notification_event_id');
    }
}
