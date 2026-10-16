<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit of platform catalog changes (plans, versions, features, policies).
 */
class SubscriptionCatalogAudit extends Model
{
    public $timestamps = false;

    protected $table = 'subscription_catalog_audits';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'operation',
        'actor_id',
        'actor_role',
        'reason',
        'before_state',
        'after_state',
        'correlation_id',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'actor_id' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Subscription catalog audit records cannot be updated.');
        });
        static::deleting(function () {
            throw new \RuntimeException('Subscription catalog audit records cannot be deleted.');
        });
    }
}
