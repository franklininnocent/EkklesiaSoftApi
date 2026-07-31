<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State Model
 * 
 * Represents a state, province, region, or territory within a country.
 * Used for cascading dropdown functionality in address forms.
 */
class State extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'states';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'country_id',
        'name',
        'state_code',
        'type',
        'latitude',
        'longitude',
        'active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'country_id' => 'integer',
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
     * Get the country that owns this state.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Scope a query to only include active states.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to order states by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }

    /**
     * Scope a query to filter states by country.
     */
    public function scopeForCountry($query, int $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    /**
     * Get the state's full name with code.
     */
    public function getFullNameAttribute(): string
    {
        return $this->state_code ? "{$this->name} ({$this->state_code})" : $this->name;
    }

    /**
     * Get the state's display name with type.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->type ? "{$this->name} ({$this->type})" : $this->name;
    }
}


