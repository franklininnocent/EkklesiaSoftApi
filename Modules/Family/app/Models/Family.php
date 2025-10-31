<?php

namespace Modules\Family\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Modules\BCC\Models\BCC;
use App\Models\User;
use Modules\Family\Database\Factories\FamilyFactory;

class Family extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return FamilyFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'families';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'family_code',
        'family_name',
        'head_of_family',
        'address_line_1',
        'address_line_2',
        'city',
        'state_id',
        'country_id',
        'postal_code',
        'primary_phone',
        'secondary_phone',
        'email',
        'bcc_id',
        'status',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot function to auto-generate family code
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($family) {
            if (empty($family->family_code)) {
                $family->family_code = static::generateFamilyCode($family->tenant_id);
            }
        });
    }

    /**
     * Generate unique family code for tenant
     *
     * @param string $tenantId
     * @return string
     */
    protected static function generateFamilyCode(string $tenantId): string
    {
        // Get all family codes for this tenant and find the highest number
        $codes = static::where('tenant_id', $tenantId)
            ->pluck('family_code')
            ->filter(function ($code) {
                return preg_match('/^FAM(\d+)$/', $code);
            })
            ->map(function ($code) {
                preg_match('/FAM(\d+)$/', $code, $matches);
                return intval($matches[1]);
            });

        $number = $codes->isEmpty() ? 1 : $codes->max() + 1;

        return 'FAM' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get the tenant that owns the family.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the country of the family.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the state of the family.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    // Parish Zone removed

    /**
     * Get the BCC that this family belongs to.
     */
    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class);
    }

    /**
     * Get all members of the family.
     */
    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /**
     * Get active members of the family.
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class)->where('status', 'active');
    }

    /**
     * Get the user who created the family.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the family.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include families of a specific tenant.
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
     * Scope a query to only include active families.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to filter by BCC.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $bccId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInBCC($query, string $bccId)
    {
        return $query->where('bcc_id', $bccId);
    }

    // Parish Zone removed

    /**
     * Get the total number of members in the family.
     *
     * @return int
     */
    public function getMemberCountAttribute(): int
    {
        return $this->members()->count();
    }

    /**
     * Get the total number of active members in the family.
     *
     * @return int
     */
    public function getActiveMemberCountAttribute(): int
    {
        return $this->activeMembers()->count();
    }
}

