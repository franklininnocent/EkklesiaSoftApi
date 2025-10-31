<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchStatistic;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Database\Factories\TenantFactory;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return TenantFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tenants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'slogan',
        'slug',
        'domain',
        'plan',
        'max_users',
        'max_storage_mb',
        'trial_ends_at',
        'subscription_ends_at',
        'active',
        'settings',
        'features',
        'logo_url',
        'primary_color',
        'secondary_color',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'integer',
        'max_users' => 'integer',
        'max_storage_mb' => 'integer',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'settings' => 'array',
        'features' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden.
     *
     * @var array<string>
     */
    protected $hidden = [];

    /**
     * Get the users belonging to this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    /**
     * Get the admin users for this tenant.
     */
    public function admins()
    {
        return $this->users()
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['EkklesiaAdmin', 'EkklesiaManager']);
            });
    }

    /**
     * Get the primary contact user for this tenant.
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(User::class, 'tenant_id')
            ->where('user_type', User::USER_TYPE_PRIMARY_CONTACT);
    }

    /**
     * Get the secondary contact user for this tenant.
     */
    public function secondaryContact(): HasOne
    {
        return $this->hasOne(User::class, 'tenant_id')
            ->where('user_type', User::USER_TYPE_SECONDARY_CONTACT);
    }

    /**
     * Get all contact users (primary and secondary) for this tenant.
     */
    public function contactUsers(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id')
            ->whereIn('user_type', [User::USER_TYPE_PRIMARY_CONTACT, User::USER_TYPE_SECONDARY_CONTACT]);
    }

    /**
     * Get all addresses for this tenant (polymorphic).
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the official address for this tenant.
     */
    public function officialAddress(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('address_type', 'official')
            ->where('active', 1);
    }

    /**
     * Get the primary address for this tenant.
     */
    public function primaryAddress(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('address_type', 'primary')
            ->where('active', 1);
    }

    /**
     * Get all active addresses for this tenant.
     */
    public function activeAddresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('active', 1);
    }

    /**
     * Get the church profile for this tenant.
     * One-to-one relationship.
     */
    public function churchProfile(): HasOne
    {
        return $this->hasOne(ChurchProfile::class);
    }

    /**
     * Get the church leadership (pastors, associate pastors, etc.) for this tenant.
     */
    public function churchLeadership(): HasMany
    {
        return $this->hasMany(ChurchLeadership::class);
    }

    /**
     * Get the primary pastor for this tenant.
     */
    public function primaryPastor(): HasOne
    {
        return $this->hasOne(ChurchLeadership::class)
            ->where('is_primary', 1)
            ->where('active', 1)
            ->latest('appointed_date');
    }

    /**
     * Get the church statistics for this tenant.
     */
    public function churchStatistics(): HasMany
    {
        return $this->hasMany(ChurchStatistic::class);
    }

    /**
     * Get the social media accounts for this tenant.
     */
    public function socialMedia(): HasMany
    {
        return $this->hasMany(ChurchSocialMedia::class);
    }

    /**
     * Get the active social media accounts for this tenant.
     */
    public function activeSocialMedia(): HasMany
    {
        return $this->hasMany(ChurchSocialMedia::class)
            ->where('active', 1)
            ->orderBy('display_order');
    }

    /**
     * Get the user who created this tenant.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this tenant.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active tenants.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1)->whereNull('deleted_at');
    }

    /**
     * Scope a query to only include inactive tenants.
     */
    public function scopeInactive($query)
    {
        return $query->where('active', 0);
    }

    /**
     * Scope a query by plan type.
     */
    public function scopeByPlan($query, $plan)
    {
        return $query->where('plan', $plan);
    }

    /**
     * Scope a query to only include tenants with active subscriptions.
     */
    public function scopeSubscribed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('subscription_ends_at')
              ->orWhere('subscription_ends_at', '>', now());
        });
    }

    /**
     * Scope a query to only include tenants in trial.
     */
    public function scopeInTrial($query)
    {
        return $query->where('trial_ends_at', '>', now());
    }

    /**
     * Check if tenant is active.
     */
    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * Activate the tenant.
     */
    public function activate(): bool
    {
        return $this->update(['active' => 1]);
    }

    /**
     * Deactivate the tenant.
     */
    public function deactivate(): bool
    {
        return $this->update(['active' => 0]);
    }

    /**
     * Check if tenant subscription is active.
     */
    public function hasActiveSubscription(): bool
    {
        if (is_null($this->subscription_ends_at)) {
            return true; // No expiration = lifetime
        }

        return $this->subscription_ends_at->isFuture();
    }

    /**
     * Check if tenant is in trial period.
     */
    public function isInTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if tenant has exceeded user limit.
     */
    public function hasExceededUserLimit(): bool
    {
        return $this->users()->count() >= $this->max_users;
    }

    /**
     * Get remaining user slots.
     */
    public function getRemainingUserSlots(): int
    {
        return max(0, $this->max_users - $this->users()->count());
    }

    /**
     * Check if tenant has a specific feature enabled.
     */
    public function hasFeature(string $feature): bool
    {
        if (is_null($this->features)) {
            return false;
        }

        return in_array($feature, $this->features);
    }

    /**
     * Get a specific setting value.
     */
    public function getSetting(string $key, $default = null)
    {
        if (is_null($this->settings)) {
            return $default;
        }

        return $this->settings[$key] ?? $default;
    }

    /**
     * Set a specific setting value.
     */
    public function setSetting(string $key, $value): bool
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;

        return $this->update(['settings' => $settings]);
    }

    /**
     * Get the primary contact user's name.
     */
    public function getPrimaryContactNameAttribute(): ?string
    {
        return $this->primaryContact?->name;
    }

    /**
     * Get the primary contact user's email.
     */
    public function getPrimaryContactEmailAttribute(): ?string
    {
        return $this->primaryContact?->email;
    }

    /**
     * Get the secondary contact user's name.
     */
    public function getSecondaryContactNameAttribute(): ?string
    {
        return $this->secondaryContact?->name;
    }

    /**
     * Get the secondary contact user's email.
     */
    public function getSecondaryContactEmailAttribute(): ?string
    {
        return $this->secondaryContact?->email;
    }

    /**
     * Check if tenant has a primary contact user.
     */
    public function hasPrimaryContact(): bool
    {
        return $this->primaryContact()->exists();
    }

    /**
     * Check if tenant has a secondary contact user.
     */
    public function hasSecondaryContact(): bool
    {
        return $this->secondaryContact()->exists();
    }

    /**
     * Get tenant with all related data (eager loading).
     */
    public static function withFullData()
    {
        return self::with([
            'primaryContact.addresses',
            'secondaryContact.addresses',
            'addresses',
            'users',
        ]);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate slug from name if not provided
        static::creating(function ($tenant) {
            if (empty($tenant->slug)) {
                $tenant->slug = \Str::slug($tenant->name);
            }
        });

        // Set created_by and updated_by
        static::creating(function ($tenant) {
            if (auth()->check()) {
                $tenant->created_by = auth()->id();
                $tenant->updated_by = auth()->id();
            }
        });

        static::updating(function ($tenant) {
            if (auth()->check()) {
                $tenant->updated_by = auth()->id();
            }
        });
    }
}

