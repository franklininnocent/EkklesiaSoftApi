<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Archdiocese Model
 * 
 * Represents ecclesiastical administrative regions (Archdiocese or Diocese).
 * Supports hierarchical structure through parent_archdiocese_id.
 */
class Archdiocese extends Model
{
    use HasFactory;

    protected $table = 'archdioceses';

    protected $fillable = [
        'name',
        'code',
        'country',
        'region',
        'headquarters_city',
        'denomination_id',
        'parent_archdiocese_id',
        'description',
        'website',
        'active',
        'country_id',
        'state_id',
    ];

    protected $casts = [
        'active' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the denomination this archdiocese belongs to
     */
    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }

    /**
     * Get the country this archdiocese belongs to
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * Get the state/province this archdiocese belongs to
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    /**
     * Get the parent archdiocese (for hierarchical structure)
     */
    public function parentArchdiocese(): BelongsTo
    {
        return $this->belongsTo(Archdiocese::class, 'parent_archdiocese_id');
    }

    /**
     * Get child archdioceses (for hierarchical structure)
     */
    public function childArchdioceses(): HasMany
    {
        return $this->hasMany(Archdiocese::class, 'parent_archdiocese_id');
    }

    /**
     * Get bishops serving in this archdiocese
     */
    public function bishops(): HasMany
    {
        return $this->hasMany(Bishop::class);
    }

    /**
     * Get church profiles under this archdiocese
     */
    public function churchProfiles(): HasMany
    {
        return $this->hasMany(ChurchProfile::class);
    }

    /**
     * Scope: Get only active archdioceses
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope: Filter by country
     */
    public function scopeByCountry($query, string $country)
    {
        return $query->where('country', $country);
    }
}
