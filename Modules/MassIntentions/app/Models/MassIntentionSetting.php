<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Model;

class MassIntentionSetting extends Model
{
    protected $table = 'mass_intention_settings';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'suggested_offering_amount',
        'review_days',
        'provincial_collective_authorized',
        'categories',
    ];

    protected function casts(): array
    {
        return [
            'provincial_collective_authorized' => 'boolean',
            'categories' => 'array',
        ];
    }
}
