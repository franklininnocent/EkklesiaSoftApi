<?php

namespace Modules\Family\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenants\Models\Tenant;
use Modules\BCC\Models\BCC;
use App\Models\User;

class ParishZone extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'parish_zones';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'zone_code',
        'name',
        'description',
        'area',
        'boundaries',
        'coordinator_name',
        'coordinator_phone',
        'coordinator_email',
        'active',
        'display_order',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot function to auto-generate zone code
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($zone) {
            if (empty($zone->zone_code)) {
                $zone->zone_code = static::generateZoneCode($zone->tenant_id);
            }
        });
    }

    /**
     * Generate unique zone code for tenant
     *
     * @param string $tenantId
     * @return string
     */
    protected static function generateZoneCode(string $tenantId): string
    {
        $lastZone = static::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastZone && preg_match('/ZONE(\d+)$/', $lastZone->zone_code, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return 'ZONE' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the tenant that owns the parish zone.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get all families in this parish zone.
     */
    public function families(): HasMany
    {
        return $this->hasMany(Family::class, 'parish_zone_id');
    }

    /**
     * Get all BCCs in this parish zone.
     */
    public function bccs(): HasMany
    {
        return $this->hasMany(BCC::class, 'parish_zone_id');
    }

    /**
     * Get the user who created the zone.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the zone.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include zones of a specific tenant.
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
     * Scope a query to only include active zones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to order by display order.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    /**
     * Get the total number of families in this zone.
     *
     * @return int
     */
    public function getFamilyCountAttribute(): int
    {
        return $this->families()->count();
    }

    /**
     * Get the total number of BCCs in this zone.
     *
     * @return int
     */
    public function getBccCountAttribute(): int
    {
        return $this->bccs()->count();
    }
}


