<?php

namespace Modules\BCC\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenants\Models\Tenant;
use Modules\Family\Models\Family;
use App\Models\User;
use Modules\BCC\Database\Factories\BCCFactory;

class BCC extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return BCCFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bccs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'bcc_code',
        'name',
        'description',
        'meeting_place',
        'meeting_day',
        'meeting_time',
        'meeting_frequency',
        'status',
        'established_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'meeting_time' => 'datetime:H:i',
        'established_date' => 'date',
        'current_family_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['current_family_count'];

    /**
     * Boot function to auto-generate BCC code
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bcc) {
            if (empty($bcc->bcc_code)) {
                $bcc->bcc_code = static::generateBCCCode($bcc->tenant_id);
            }
        });
    }

    /**
     * Generate unique BCC code for tenant
     *
     * @param string $tenantId
     * @return string
     */
    protected static function generateBCCCode(string $tenantId): string
    {
        // Get all BCC codes for this tenant and find the highest number
        $codes = static::where('tenant_id', $tenantId)
            ->pluck('bcc_code')
            ->filter(function ($code) {
                return preg_match('/^BCC(\d+)$/', $code);
            })
            ->map(function ($code) {
                preg_match('/BCC(\d+)$/', $code, $matches);
                return intval($matches[1]);
            });

        $number = $codes->isEmpty() ? 1 : $codes->max() + 1;

        return 'BCC' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the tenant that owns the BCC.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Parish Zone removed

    /**
     * Get all families in this BCC.
     */
    public function families(): HasMany
    {
        return $this->hasMany(Family::class, 'bcc_id');
    }

    /**
     * Get active families in this BCC.
     */
    public function activeFamilies(): HasMany
    {
        return $this->hasMany(Family::class, 'bcc_id')->where('status', 'active');
    }

    /**
     * Get all leaders of this BCC.
     */
    public function leaders(): HasMany
    {
        return $this->hasMany(BCCLeader::class, 'bcc_id');
    }

    /**
     * Get active leaders of this BCC.
     */
    public function activeLeaders(): HasMany
    {
        return $this->hasMany(BCCLeader::class, 'bcc_id')->where('is_active', true);
    }

    /**
     * Get the primary leader of this BCC.
     */
    public function primaryLeader()
    {
        return $this->hasOne(BCCLeader::class, 'bcc_id')
            ->where('role', 'leader')
            ->where('is_active', true);
    }

    /**
     * Get the user who created the BCC.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the BCC.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include BCCs of a specific tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include active BCCs.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Parish Zone removed

    /**
     * Scope a query to filter BCCs with space for more families.
     * Uses a subquery to count families (since current_family_count is computed).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHasSpace($query)
    {
        return $query->whereRaw('(SELECT COUNT(*) FROM families WHERE families.bcc_id = bccs.id) < bccs.max_families');
    }

    /**
     * Get current family count (computed from families relationship).
     * This is a computed attribute - not stored in database (normalized).
     *
     * @return int
     */
    public function getCurrentFamilyCountAttribute(): int
    {
        // Use eager loaded count if available, otherwise query
        return $this->families()->count();
    }

    /**
     * Check if BCC is at capacity.
     *
     * @return bool
     */
    public function getIsAtCapacityAttribute(): bool
    {
        return $this->current_family_count >= $this->max_families;
    }

    /**
     * Get capacity percentage.
     *
     * @return float
     */
    public function getCapacityPercentageAttribute(): float
    {
        if ($this->max_families == 0) {
            return 0;
        }

        return round(($this->current_family_count / $this->max_families) * 100, 2);
    }

    /**
     * Check if BCC can accept more families.
     *
     * @return bool
     */
    public function canAcceptMoreFamilies(): bool
    {
        return $this->current_family_count < $this->max_families;
    }

    /**
     * Get remaining capacity.
     *
     * @return int
     */
    public function getRemainingCapacity(): int
    {
        return max(0, $this->max_families - $this->current_family_count);
    }
}

