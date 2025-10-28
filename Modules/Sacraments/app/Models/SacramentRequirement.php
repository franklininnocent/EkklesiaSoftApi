<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SacramentRequirement Model
 * 
 * Represents prerequisites and requirements for receiving sacraments
 */
class SacramentRequirement extends Model
{
    use HasFactory;

    protected $table = 'sacrament_requirements';

    protected $fillable = [
        'sacrament_type_id',
        'prerequisite_sacrament_id',
        'requirement_type',
        'title',
        'description',
        'is_mandatory',
        'minimum_age_years',
        'preparation_hours',
        'documentation_needed',
        'display_order',
        'active',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'minimum_age_years' => 'integer',
        'preparation_hours' => 'integer',
        'display_order' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sacrament type this requirement is for
     */
    public function sacramentType(): BelongsTo
    {
        return $this->belongsTo(SacramentType::class, 'sacrament_type_id');
    }

    /**
     * Get the prerequisite sacrament (if any)
     */
    public function prerequisiteSacrament(): BelongsTo
    {
        return $this->belongsTo(SacramentType::class, 'prerequisite_sacrament_id');
    }

    /**
     * Scope: Get only active requirements
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: Get only mandatory requirements
     */
    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('title');
    }

    /**
     * Scope: Filter by requirement type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('requirement_type', $type);
    }

    /**
     * Check if this is a mandatory requirement
     */
    public function isMandatory(): bool
    {
        return $this->is_mandatory;
    }

    /**
     * Get formatted requirement summary
     */
    public function getSummaryAttribute(): string
    {
        $parts = [$this->title];
        
        if ($this->minimum_age_years) {
            $parts[] = "Min age: {$this->minimum_age_years} years";
        }
        
        if ($this->preparation_hours) {
            $parts[] = "{$this->preparation_hours} hours preparation";
        }
        
        return implode(' | ', $parts);
    }
}
