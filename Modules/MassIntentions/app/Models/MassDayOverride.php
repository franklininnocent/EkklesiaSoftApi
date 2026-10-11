<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MassDayOverride extends Model
{
    use HasUuids;

    protected $table = 'mass_day_overrides';

    protected $fillable = [
        'tenant_id',
        'override_on',
        'mode',
        'closes_regular_masses',
        'label',
        'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'override_on' => 'date',
            'closes_regular_masses' => 'boolean',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(MassDayOverrideSlot::class, 'day_override_id');
    }
}
