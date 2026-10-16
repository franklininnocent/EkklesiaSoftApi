<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'tenant_usage_snapshots';

    protected $fillable = [
        'tenant_id',
        'feature_id',
        'snapshot_date',
        'usage_value',
        'limit_value',
        'created_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'feature_id' => 'integer',
        'snapshot_date' => 'date',
        'usage_value' => 'integer',
        'limit_value' => 'integer',
        'created_at' => 'datetime',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }
}
