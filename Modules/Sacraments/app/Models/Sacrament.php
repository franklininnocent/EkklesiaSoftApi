<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\Person;
use Modules\Sacraments\Database\Factories\SacramentFactory;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Models\Tenant;

/**
 * Sacrament Model — parish register record (ADR-07 / ADR-08).
 */
class Sacrament extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return SacramentFactory::new();
    }

    protected $table = 'sacraments';

    protected $fillable = [
        'tenant_id',
        'family_id',
        'bcc_id',
        'person_id',
        'sacrament_type_id',
        'recipient_name',
        'date_administered',
        'place_administered',
        'place_classification',
        'event_subtype',
        'typed_attributes',
        'leadership_context_json',
        'baptism_date',
        'minister_name',
        'minister_title',
        'certificate_number',
        'book_number',
        'page_number',
        'registry_entry',
        'lock_version',
        'recipient_birth_date',
        'recipient_birth_place',
        'recipient_gender',
        'father_name',
        'mother_name',
        'godparent1_name',
        'godparent2_name',
        'marriage_bride_full_name',
        'marriage_bride_father_name',
        'marriage_bride_mother_name',
        'marriage_bride_address',
        'marriage_bride_church_type',
        'marriage_bride_church_name',
        'marriage_bride_church_address',
        'marriage_bride_diocese_name',
        'marriage_bride_diocese_region',
        'marriage_bride_diocese_country',
        'marriage_groom_full_name',
        'marriage_groom_father_name',
        'marriage_groom_mother_name',
        'marriage_groom_address',
        'marriage_groom_church_type',
        'marriage_groom_church_name',
        'marriage_groom_church_address',
        'marriage_groom_diocese_name',
        'marriage_groom_diocese_region',
        'marriage_groom_diocese_country',
        'marriage_canonical_classification',
        'witnesses',
        'notes',
        'document_path',
        'status',
        'conditional_date',
        'conditional_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_administered' => 'date',
        'recipient_birth_date' => 'date',
        'baptism_date' => 'date',
        'conditional_date' => 'date',
        'typed_attributes' => 'array',
        'leadership_context_json' => 'array',
        'lock_version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    protected $attributes = [
        'lock_version' => 0,
        'status' => SacramentStatus::REGISTERED,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacramentType(): BelongsTo
    {
        return $this->belongsTo(SacramentType::class, 'sacrament_type_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SacramentParticipant::class)->orderBy('sort_order');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SacramentCertificate::class);
    }

    public function dispensations(): HasMany
    {
        return $this->hasMany(SacramentDispensation::class);
    }

    public function canonicalAnnotations(): HasMany
    {
        return $this->hasMany(SacramentCanonicalAnnotation::class)->orderBy('effective_date')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeBySacramentType($query, int $typeId)
    {
        return $query->where('sacrament_type_id', $typeId);
    }

    public function scopeByStatus($query, string $status)
    {
        $normalized = SacramentStatus::normalize($status) ?? $status;

        return $query->where('status', $normalized);
    }

    public function scopeSearchRecipient($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereRaw('LOWER(recipient_name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        });
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_administered', [$startDate, $endDate]);
    }

    public function scopeByMinisterName($query, string $ministerName)
    {
        return $query->whereRaw('LOWER(minister_name) LIKE ?', ['%'.mb_strtolower($ministerName).'%']);
    }

    public function scopeByCertificateNumber($query, string $certificateNumber)
    {
        return $query->whereRaw('LOWER(certificate_number) LIKE ?', ['%'.mb_strtolower($certificateNumber).'%']);
    }

    public function scopeByBookNumber($query, string $bookNumber)
    {
        return $query->whereRaw('LOWER(book_number) LIKE ?', ['%'.mb_strtolower($bookNumber).'%']);
    }

    public function scopeByFamily($query, string $familyId)
    {
        return $query->where('family_id', $familyId);
    }

    /**
     * Sacraments linked to a canonical family member (participant FK or parish Person).
     *
     * @param  Builder  $query
     */
    public function scopeLinkedToFamilyMember($query, string $memberId, ?string $personId = null)
    {
        return $query->where(function ($outer) use ($memberId, $personId) {
            $outer->whereHas('participants', function ($participantQuery) use ($memberId) {
                $participantQuery
                    ->where('family_member_id', $memberId)
                    ->whereIn('role', ['recipient', 'bride', 'groom']);
            });

            if ($personId !== null && $personId !== '') {
                $outer->orWhere('person_id', $personId);
            }
        });
    }

    public function scopeByBCC($query, string $bccId)
    {
        return $query->where('bcc_id', $bccId);
    }

    public function isRegistered(): bool
    {
        return SacramentStatus::normalize($this->status) === SacramentStatus::REGISTERED;
    }

    public function isVoided(): bool
    {
        return SacramentStatus::normalize($this->status) === SacramentStatus::VOIDED;
    }

    /** @deprecated Use isRegistered() — legacy alias for pre-Phase-1 status. */
    public function isActive(): bool
    {
        return $this->isRegistered();
    }

    public function getCertificateReferenceAttribute(): string
    {
        $parts = array_filter([
            $this->book_number ? "Book: {$this->book_number}" : null,
            $this->page_number ? "Page: {$this->page_number}" : null,
            $this->certificate_number ? "Cert: {$this->certificate_number}" : null,
        ]);

        return $parts !== [] ? implode(' | ', $parts) : 'No reference';
    }
}
