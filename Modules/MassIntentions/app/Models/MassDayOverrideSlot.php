<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassDayOverrideSlot extends Model
{
    use HasUuids;

    protected $table = 'mass_day_override_slots';

    protected $fillable = [
        'tenant_id',
        'day_override_id',
        'slot_id',
        'celebrated_at',
        'place',
        'celebrant_name',
        'place_key',
        'sort_order',
    ];

    public function override(): BelongsTo
    {
        return $this->belongsTo(MassDayOverride::class, 'day_override_id');
    }
}
