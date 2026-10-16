<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Country-specific billed list price for a catalog plan.
 * Amounts are stored in this row's currency_code; they are not converted.
 */
class PlanCatalogPrice extends Model
{
    protected $table = 'plan_catalog_prices';

    protected $fillable = [
        'plan_id',
        'country_code',
        'currency_code',
        'monthly_price',
        'annual_price',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'monthly_price' => 'decimal:2',
        'annual_price' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }
}
