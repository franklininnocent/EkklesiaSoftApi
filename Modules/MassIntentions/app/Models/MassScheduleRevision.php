<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MassScheduleRevision extends Model
{
    use HasUuids;

    protected $table = 'mass_schedule_revisions';

    protected $fillable = [
        'tenant_id',
        'schedule_id',
        'revision_number',
        'status',
        'effective_from',
        'effective_to',
        'content_fingerprint',
        'published_at',
        'published_by_user_id',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MassSchedule::class, 'schedule_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(MassScheduleSlot::class, 'revision_id');
    }
}
