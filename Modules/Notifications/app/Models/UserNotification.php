<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserNotification extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'notification_event_id', 'user_id', 'inbox_scope', 'tenant_id', 'status',
        'read_at', 'archived_at', 'action_status', 'is_mention', 'collapse_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_mention' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'user_notification_id');
    }
}
