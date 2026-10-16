<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EcclesiasticalTitle Model
 * 
 * Represents titles like Bishop, Archbishop, Cardinal, etc.
 */
class EcclesiasticalTitle extends Model
{
    use HasFactory;

    protected $table = 'ecclesiastical_titles';

    protected $fillable = [
        'title',
        'abbreviation',
        'description',
        'hierarchy_level',
        'display_order',
        'active',
    ];

    protected $casts = [
        'hierarchy_level' => 'integer',
        'display_order' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Alias for title (for compatibility)
     */
    public function getNameAttribute(): string
    {
        return $this->title;
    }

    /**
     * Get bishops with this title
     */
    public function bishops(): HasMany
    {
        return $this->hasMany(BishopManagement::class, 'ecclesiastical_title_id');
    }

    /**
     * Scope: Get only active titles
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: Order by hierarchy level
     */
    public function scopeOrderByHierarchy($query)
    {
        return $query->orderBy('hierarchy_level');
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrderByDisplay($query)
    {
        return $query->orderBy('display_order');
    }
}

