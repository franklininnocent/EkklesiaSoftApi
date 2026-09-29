<?php

namespace Modules\EcclesiasticalData\Models;

use Modules\Tenants\Models\Archdiocese;
use Modules\EcclesiasticalData\Support\DioceseLeadershipState;
use Modules\EcclesiasticalData\Traits\HasAuditTrail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class DioceseManagement extends Archdiocese
{
    use HasFactory, HasAuditTrail;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\EcclesiasticalData\Database\Factories\DioceseManagementFactory::new();
    }

    /**
     * Get fillable attributes including parent
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), [
            'is_active',
            'leadership_state',
            'metadata',
            'last_verified_at',
            'last_verified_by',
            'verification_notes',
        ]);
    }

    /**
     * Get casts including parent
     */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), [
            'metadata' => 'array',
            'leadership_state' => DioceseLeadershipState::class,
            'last_verified_at' => 'datetime',
        ]);
    }

    /**
     * Get the hierarchy relationships where this is metropolitan (archdiocese)
     */
    public function suffraganDioceses(): HasMany
    {
        return $this->hasMany(DiocesesHierarchy::class, 'metropolitan_id');
    }

    /**
     * Get the hierarchy relationship where this is suffragan (diocese)
     */
    public function metropolitanRelation(): BelongsTo
    {
        return $this->belongsTo(DiocesesHierarchy::class, 'id', 'suffragan_id');
    }

    /**
     * Get bishops appointed to this diocese
     */
    public function bishopAppointments(): HasMany
    {
        return $this->hasMany(BishopAppointment::class, 'diocese_id');
    }

    /**
     * Get current diocesan ordinary appointment.
     */
    public function currentOrdinaryAppointment()
    {
        return $this->bishopAppointments()
            ->where('is_current', true)
            ->ordinary()
            ->with(['bishop', 'ecclesiasticalTitle'])
            ->orderBy('effective_date', 'desc')
            ->first();
    }

    /**
     * Get current bishop (ordinary).
     */
    public function currentBishop()
    {
        return $this->currentOrdinaryAppointment()?->bishop;
    }

    /**
     * Get all current episcopal appointments (ordinary, auxiliary, coadjutor, etc.).
     */
    public function currentAppointments()
    {
        return $this->bishopAppointments()
            ->where('is_current', true)
            ->with(['bishop', 'ecclesiasticalTitle'])
            ->orderBy('canonical_role')
            ->get();
    }

    public function updateRequests(): HasMany
    {
        return $this->hasMany(BishopUpdateRequest::class, 'diocese_id');
    }

    public function isLeadershipVacant(): bool
    {
        return $this->leadership_state === DioceseLeadershipState::Vacant;
    }

    /**
     * Get data quality issues
     */
    public function qualityIssues(): HasMany
    {
        return $this->hasMany(EcclesiasticalDataQuality::class, 'entity_id')
            ->where('entity_type', 'archdioceses');
    }

    /**
     * Scope for active dioceses only
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope for search functionality
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
              ->orWhere('code', 'ILIKE', "%{$search}%")
              ->orWhere('website', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Scope for filtering by country ID
     */
    public function scopeByCountryId($query, ?int $countryId)
    {
        if (!$countryId) {
            return $query;
        }

        return $query->where('country_id', $countryId);
    }

    /**
     * Scope for filtering by denomination
     */
    public function scopeByDenomination($query, ?int $denominationId)
    {
        if (!$denominationId) {
            return $query;
        }

        return $query->where('denomination_id', $denominationId);
    }
}

