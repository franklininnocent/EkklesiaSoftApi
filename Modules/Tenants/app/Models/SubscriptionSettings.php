<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform singleton for Super Admin subscription access settings.
 */
class SubscriptionSettings extends Model
{
    protected $table = 'subscription_settings';

    protected $fillable = [
        'grace_period_days',
        'expiring_warning_days',
    ];

    protected $casts = [
        'grace_period_days' => 'integer',
        'expiring_warning_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the singleton settings row (creates defaults if missing).
     */
    public static function current(): self
    {
        $settings = static::query()->orderBy('id')->first();

        if ($settings) {
            return $settings;
        }

        return static::query()->create([
            'grace_period_days' => (int) config('tenants.subscription.grace_period_days', 7),
            'expiring_warning_days' => (int) config('tenants.subscription.expiring_warning_days', 14),
        ]);
    }
}
