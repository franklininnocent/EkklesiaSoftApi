<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ReligiousOrder Model
 * 
 * Represents religious orders and congregations (e.g., SJ, OFM, OP, etc.)
 */
class ReligiousOrder extends Model
{
    use HasFactory;

    protected $table = 'religious_orders';

    protected $fillable = [
        'name',
        'abbreviation',
        'full_name',
        'founded_year',
        'founder',
        'charism',
        'description',
        'website',
        'active',
    ];

    protected $casts = [
        'founded_year' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get bishops from this religious order
     */
    public function bishops(): HasMany
    {
        return $this->hasMany(BishopManagement::class, 'religious_order_id');
    }

    /**
     * Scope: Get only active orders
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Get display name (abbreviation or name)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->abbreviation ?: $this->name;
    }
}

