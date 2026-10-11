<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassCelebration extends Model
{
    use HasUuids;

    protected $table = 'mass_celebrations';

    protected $fillable = [
        'tenant_id',
        'origin',
        'schedule_id',
        'revision_id',
        'slot_id',
        'day_override_id',
        'celebrated_on',
        'celebrated_at',
        'place',
        'place_source',
        'celebrant_name',
        'celebrant_source',
        'celebrant_leadership_assignment_id',
        'status',
        'generation_status',
        'suppression_reason',
        'suppressed_at',
        'source_label',
        'is_exception',
        'timezone',
        'occasion',
        'notes',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'celebrated_on' => 'date',
            'suppressed_at' => 'datetime',
            'is_exception' => 'boolean',
        ];
    }

    public function isScheduledOccurrence(): bool
    {
        return $this->slot_id !== null;
    }
}
