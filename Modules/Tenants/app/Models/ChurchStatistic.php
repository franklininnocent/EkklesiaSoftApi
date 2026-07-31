<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChurchStatistic Model
 * 
 * Time-series data for church statistics including membership, attendance,
 * sacraments, and financial metrics.
 * Supports both monthly and annual reporting.
 */
class ChurchStatistic extends Model
{
    use HasFactory;

    protected $table = 'church_statistics';

    protected $fillable = [
        'tenant_id',
        'year',
        'month',
        'membership_count',
        'weekly_attendance',
        'baptisms',
        'confirmations',
        'marriages',
        'funerals',
        'tithes_offerings',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'membership_count' => 'integer',
        'weekly_attendance' => 'integer',
        'baptisms' => 'integer',
        'confirmations' => 'integer',
        'marriages' => 'integer',
        'funerals' => 'integer',
        'tithes_offerings' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant/church these statistics belong to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope: Get statistics for a specific year
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    /**
     * Scope: Get statistics for a specific month
     */
    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Scope: Get annual statistics (where month is null)
     */
    public function scopeAnnual($query)
    {
        return $query->whereNull('month');
    }

    /**
     * Scope: Get monthly statistics
     */
    public function scopeMonthly($query)
    {
        return $query->whereNotNull('month');
    }

    /**
     * Scope: Latest statistics first
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('year', 'desc')->orderBy('month', 'desc');
    }

    /**
     * Check if this is an annual statistic
     */
    public function isAnnual(): bool
    {
        return $this->month === null;
    }

    /**
     * Get formatted period string
     */
    public function getPeriodAttribute(): string
    {
        if ($this->isAnnual()) {
            return (string) $this->year;
        }
        return date('F Y', mktime(0, 0, 0, $this->month, 1, $this->year));
    }
}
