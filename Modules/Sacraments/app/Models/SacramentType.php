<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SacramentType Model
 * 
 * Represents types of Catholic sacraments (Baptism, Confirmation, etc.)
 */
class SacramentType extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Sacraments\Database\Factories\SacramentTypeFactory::new();
    }

    protected $table = 'sacrament_types';

    protected $fillable = [
        'name',
        'code',
        'category',
        'description',
        'theological_significance',
        'display_order',
        'min_age_years',
        'typical_age_years',
        'repeatable',
        'requires_minister',
        'minister_type',
        'active',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'min_age_years' => 'integer',
        'typical_age_years' => 'integer',
        'repeatable' => 'boolean',
        'requires_minister' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all sacraments of this type
     */
    public function sacraments(): HasMany
    {
        return $this->hasMany(Sacrament::class, 'sacrament_type_id');
    }

    /**
     * Get all requirements for this sacrament type
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(SacramentRequirement::class, 'sacrament_type_id');
    }

    /**
     * Get sacrament types that require this one as prerequisite
     */
    public function isPrerequisiteFor(): HasMany
    {
        return $this->hasMany(SacramentRequirement::class, 'prerequisite_sacrament_id');
    }

    /**
     * Scope: Get only active sacrament types
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    /**
     * Scope: Filter by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Check if sacrament can be repeated
     */
    public function isRepeatable(): bool
    {
        return $this->repeatable;
    }

    /**
     * Get formatted age range
     */
    public function getAgeRangeAttribute(): string
    {
        if ($this->min_age_years && $this->typical_age_years) {
            return "{$this->min_age_years}-{$this->typical_age_years} years";
        } elseif ($this->min_age_years) {
            return "{$this->min_age_years}+ years";
        } elseif ($this->typical_age_years) {
            return "Around {$this->typical_age_years} years";
        }
        return 'No age restriction';
    }
}
