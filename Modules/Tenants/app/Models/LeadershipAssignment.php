<?php

namespace Modules\Tenants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Factories\LeadershipAssignmentFactory;
use Modules\Tenants\Support\LeadershipAssignmentStatus;

class LeadershipAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'leadership_assignments';

    protected $fillable = [
        'tenant_id',
        'church_profile_id',
        'person_id',
        'role_id',
        'jurisdiction_name',
        'appointment_date',
        'start_date',
        'end_date',
        'status',
        'appointment_letter_ref',
        'photo_url',
        'exit_reason_code',
        'exit_reason_note',
        'legacy_church_leadership_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'legacy_church_leadership_id' => 'integer',
    ];

    protected static function newFactory(): LeadershipAssignmentFactory
    {
        return LeadershipAssignmentFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function churchProfile(): BelongsTo
    {
        return $this->belongsTo(ChurchProfile::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(LeadershipRole::class, 'role_id');
    }

    public function legacyChurchLeadership(): BelongsTo
    {
        return $this->belongsTo(ChurchLeadership::class, 'legacy_church_leadership_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', LeadershipAssignmentStatus::ACTIVE);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForChurchProfile($query, int $churchProfileId)
    {
        return $query->where('church_profile_id', $churchProfileId);
    }

    public function isActive(): bool
    {
        return $this->status === LeadershipAssignmentStatus::ACTIVE;
    }
}
