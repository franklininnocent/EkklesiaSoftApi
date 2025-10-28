<?php

namespace Modules\EcclesiasticalData\Models;

use Modules\Tenants\Models\Bishop;
use Modules\EcclesiasticalData\Traits\HasAuditTrail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class BishopManagement extends Bishop
{
    use HasFactory, HasAuditTrail;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\EcclesiasticalData\Database\Factories\BishopManagementFactory::new();
    }

    /**
     * Get fillable attributes including parent
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), [
            'is_active',
            'metadata',
            'last_verified_at',
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
            'last_verified_at' => 'datetime',
        ]);
    }

    /**
     * Get all appointments for this bishop
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(BishopAppointment::class, 'bishop_id');
    }

    /**
     * Get current appointment
     */
    public function currentAppointment()
    {
        return $this->appointments()
            ->where('is_current', true)
            ->with(['diocese', 'ecclesiasticalTitle'])
            ->first();
    }

    /**
     * Get data quality issues
     */
    public function qualityIssues(): HasMany
    {
        return $this->hasMany(EcclesiasticalDataQuality::class, 'entity_id')
            ->where('entity_type', 'bishops');
    }

    /**
     * Scope for active bishops
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('full_name', 'ILIKE', "%{$search}%")
              ->orWhere('given_name', 'ILIKE', "%{$search}%")
              ->orWhere('family_name', 'ILIKE', "%{$search}%")
              ->orWhere('email', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Scope by diocese
     */
    public function scopeByDiocese($query, ?string $dioceseId)
    {
        if (!$dioceseId) {
            return $query;
        }

        return $query->whereHas('appointments', function ($q) use ($dioceseId) {
            $q->where('diocese_id', $dioceseId)
              ->where('is_current', true);
        });
    }

    /**
     * Scope by title
     */
    public function scopeByTitle($query, ?string $titleId)
    {
        if (!$titleId) {
            return $query;
        }

        return $query->where('ecclesiastical_title_id', $titleId);
    }
}

