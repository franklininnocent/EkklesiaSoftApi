<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

class BillingOfflineMethod extends Model
{
    protected $table = 'billing_offline_methods';

    protected $fillable = [
        'code',
        'label',
        'enabled',
        'instructions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'instructions' => 'array',
        ];
    }
}
