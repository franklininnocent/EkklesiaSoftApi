<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

/**
 * Create/batch Idempotency-Key store (§5.6). Wired in Phase 3.
 */
class SacramentIdempotencyKey extends Model
{
    public $timestamps = false;

    protected $table = 'sacrament_idempotency_keys';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'request_hash',
        'sacrament_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }
}
