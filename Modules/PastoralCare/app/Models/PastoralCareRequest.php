<?php

namespace Modules\PastoralCare\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\Person;
use Modules\PastoralCare\Database\Factories\PastoralCareRequestFactory;
use Modules\PastoralCare\Support\PastoralCareStatus;

class PastoralCareRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pastoral_care_requests';

    protected $fillable = [
        'tenant_id',
        'family_id',
        'person_id',
        'type',
        'priority',
        'status',
        'summary',
        'notes',
        'due_on',
        'created_by_user_id',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'completed_at',
    ];

    protected $casts = [
        'due_on' => 'date',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'tenant_id' => 'integer',
        'created_by_user_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'assigned_by_user_id' => 'integer',
    ];

    protected static function newFactory(): PastoralCareRequestFactory
    {
        return PastoralCareRequestFactory::new();
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', PastoralCareStatus::active());
    }
}
