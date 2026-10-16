<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BillingPromotion extends Model
{
    protected $table = 'billing_promotions';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'code',
        'name',
        'status',
        'discount_type',
        'discount_value',
        'currency_code',
        'discounted_periods',
        'renewal_policy',
        'requires_assignment',
        'max_redemptions',
        'redemption_count',
        'starts_at',
        'ends_at',
        'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'requires_assignment' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'billing_promotion_plans', 'billing_promotion_id', 'plan_id')
            ->withPivot('billing_interval');
    }
}
