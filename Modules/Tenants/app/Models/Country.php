<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Country Model
 * 
 * Represents a country with ISO codes, currency, and geographic information.
 * Related to states/provinces for cascading dropdown functionality.
 */
class Country extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\Tenants\Database\Factories\CountryFactory::new();
    }
    /**
     * The table associated with the model.
     */
    protected $table = 'countries';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'iso3',
        'iso2',
        'numeric_code',
        'phone_code',
        'capital',
        'currency',
        'currency_name',
        'currency_symbol',
        'tld',
        'native',
        'latitude',
        'longitude',
        'region',
        'subregion',
        'emoji',
        'emoji_u',
        'active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Get all states/provinces for this country.
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    /**
     * Get only active states/provinces for this country.
     */
    public function activeStates(): HasMany
    {
        return $this->states()->where('active', true)->orderBy('name');
    }

    /**
     * Scope a query to only include active countries.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to order countries by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }

    /**
     * Get the country's full name with emoji.
     */
    public function getFullNameAttribute(): string
    {
        return $this->emoji ? "{$this->emoji} {$this->name}" : $this->name;
    }

    /**
     * Get the country's name with ISO code.
     */
    public function getNameWithCodeAttribute(): string
    {
        return "{$this->name} ({$this->iso2})";
    }
}


