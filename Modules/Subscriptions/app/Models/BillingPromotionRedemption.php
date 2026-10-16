<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPromotionRedemption extends Model
{
    protected $table = 'billing_promotion_redemptions';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'billing_promotion_id',
        'tenant_id',
        'billing_order_id',
        'status',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillingOrder::class, 'billing_order_id');
    }
}
