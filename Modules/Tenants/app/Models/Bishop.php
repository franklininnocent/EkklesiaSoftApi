<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bishop Model
 * 
 * Represents information about bishops, archbishops, and other overseers.
 */
class Bishop extends Model
{
    use HasFactory;

    protected $table = 'bishops';

    protected $fillable = [
        'full_name',
        'title',
        'archdiocese_id',
        'ordained_date',
        'appointed_date',
        'email',
        'phone',
        'biography',
        'photo_url',
        'active',
    ];

    protected $casts = [
        'ordained_date' => 'date',
        'appointed_date' => 'date',
        'active' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the archdiocese this bishop oversees
     */
    public function archdiocese(): BelongsTo
    {
        return $this->belongsTo(Archdiocese::class);
    }

    /**
     * Get church profiles under this bishop
     */
    public function churchProfiles(): HasMany
    {
        return $this->hasMany(ChurchProfile::class);
    }

    /**
     * Scope: Get only active bishops
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Get the full title and name
     */
    public function getFullTitleAttribute(): string
    {
        return trim($this->title . ' ' . $this->full_name);
    }
}
