<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_notification_id', 'channel', 'status', 'attempts',
        'next_retry_at', 'last_error_code', 'provider_id',
    ];

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
        ];
    }

    public function userNotification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'user_notification_id');
    }
}
