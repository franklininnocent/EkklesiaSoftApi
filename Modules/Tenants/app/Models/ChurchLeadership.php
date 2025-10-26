<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChurchLeadership Model
 * 
 * Represents church leaders including pastors, associate pastors, and other ministry leaders.
 * Supports multiple leaders per church with role hierarchy.
 */
class ChurchLeadership extends Model
{
    use HasFactory;

    protected $table = 'church_leadership';

    protected $fillable = [
        'tenant_id',
        'full_name',
        'role',
        'title',
        'email',
        'phone',
        'appointed_date',
        'start_date',
        'end_date',
        'biography',
        'photo_url',
        'is_primary',
        'display_order',
        'active',
    ];

    protected $casts = [
        'appointed_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_primary' => 'integer',
        'display_order' => 'integer',
        'active' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant/church this leader belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope: Get only active leaders
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope: Get primary pastor
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', 1);
    }

    /**
     * Scope: Get current leaders (no end date or end date in future)
     */
    public function scopeCurrent($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', now());
        });
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('is_primary', 'desc')
                     ->orderBy('display_order')
                     ->orderBy('appointed_date', 'desc');
    }

    /**
     * Get the full title and name
     */
    public function getFullTitleAttribute(): string
    {
        return trim(($this->title ?? '') . ' ' . $this->full_name);
    }
}
