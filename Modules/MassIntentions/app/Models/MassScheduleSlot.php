<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassScheduleSlot extends Model
{
    use HasUuids;

    protected $table = 'mass_schedule_slots';

    protected $fillable = [
        'tenant_id',
        'revision_id',
        'slot_id',
        'weekday',
        'weeks_of_month',
        'celebrated_at',
        'place',
        'celebrant_name',
        'place_source',
        'celebrant_source',
        'place_key',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weeks_of_month' => 'array',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(MassScheduleRevision::class, 'revision_id');
    }
}
