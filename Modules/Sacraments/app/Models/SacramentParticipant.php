<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\Tenant;

/**
 * First-class sacrament participant (ADR-01). Written in Phase 3+; schema only in Phase 2.
 */
class SacramentParticipant extends Model
{
    use SoftDeletes;

    protected $table = 'sacrament_participants';

    protected $fillable = [
        'tenant_id',
        'sacrament_id',
        'role',
        'source',
        'family_member_id',
        'person_id',
        'church_leadership_id',
        'sort_order',
        'affiliation_type',
        'affiliation_parish_name',
        'affiliation_parish_address',
        'affiliation_diocese_name',
        'affiliation_diocese_region',
        'affiliation_diocese_country',
        'external_full_name',
        'external_date_of_birth',
        'external_gender',
        'external_address',
        'external_contact_number',
        'external_title',
        'external_minister_role',
        'snapshot_json',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'external_date_of_birth' => 'date',
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->snapshot_json === null) {
                $model->snapshot_json = [];
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'family_member_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function churchLeadership(): BelongsTo
    {
        return $this->belongsTo(ChurchLeadership::class, 'church_leadership_id');
    }
}
