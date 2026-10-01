<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MassSchedule extends Model
{
    use HasUuids;

    protected $table = 'mass_schedules';

    protected $fillable = [
        'tenant_id',
        'name',
        'kind',
        'coverage_mode',
        'selected_weekdays',
        'status',
        'last_preview_conflict_count',
        'last_preview_at',
        'default_place',
        'default_celebrant_name',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'selected_weekdays' => 'array',
            'last_preview_at' => 'datetime',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MassScheduleRevision::class, 'schedule_id');
    }
}
