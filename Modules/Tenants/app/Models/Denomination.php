<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Denomination Model
 * 
 * Represents church denominations or rites (e.g., Catholic, Protestant, Orthodox).
 * This is a lookup table used for classification and reporting.
 */
class Denomination extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\Tenants\Database\Factories\DenominationFactory::new();
    }

    protected $table = 'denominations';

    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
        'display_order',
    ];

    protected $casts = [
        'active' => 'integer',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get archdioceses belonging to this denomination
     */
    public function archdioceses(): HasMany
    {
        return $this->hasMany(Archdiocese::class);
    }

    /**
     * Get church profiles using this denomination
     */
    public function churchProfiles(): HasMany
    {
        return $this->hasMany(ChurchProfile::class);
    }

    /**
     * Scope: Get only active denominations
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
