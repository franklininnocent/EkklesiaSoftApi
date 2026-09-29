<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Database\Factories\MarriagePreparationCaseFactory;
use Modules\Sacraments\Support\MarriagePreparationStatus;
use Modules\Tenants\Models\Tenant;

class MarriagePreparationCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'marriage_preparation_cases';

    protected $fillable = [
        'tenant_id',
        'bcc_id',
        'bride_family_member_id',
        'groom_family_member_id',
        'sacrament_id',
        'status',
        'inquiry_started_at',
        'pre_cana_completed_at',
        'banns_published_at',
        'canonical_docs_verified_at',
        'intended_marriage_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'inquiry_started_at' => 'datetime',
        'pre_cana_completed_at' => 'datetime',
        'banns_published_at' => 'datetime',
        'canonical_docs_verified_at' => 'datetime',
        'intended_marriage_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => MarriagePreparationStatus::ACTIVE,
    ];

    protected static function newFactory(): MarriagePreparationCaseFactory
    {
        return MarriagePreparationCaseFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class, 'bcc_id');
    }

    public function brideFamilyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'bride_family_member_id');
    }

    public function groomFamilyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'groom_family_member_id');
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', MarriagePreparationStatus::ACTIVE);
    }
}
