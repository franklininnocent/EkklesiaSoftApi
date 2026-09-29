<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;

/**
 * Legacy → participant migration resolution row (ADR-11). Used in Phase 9.
 */
class SacramentMigrationResolution extends Model
{
    protected $table = 'sacrament_migration_resolutions';

    protected $fillable = [
        'tenant_id',
        'legacy_sacrament_id',
        'participant_role',
        'legacy_name',
        'legacy_dob',
        'candidate_member_id',
        'confidence',
        'resolution',
        'resolved_by',
        'resolved_at',
        'migration_key',
    ];

    protected $casts = [
        'legacy_dob' => 'date',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function legacySacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class, 'legacy_sacrament_id');
    }

    public function candidateMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'candidate_member_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
