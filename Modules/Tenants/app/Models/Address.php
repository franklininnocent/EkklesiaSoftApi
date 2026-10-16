<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Address Model
 * 
 * Polymorphic model that can belong to multiple entity types (User, Tenant, etc.)
 * Supports multiple address types (primary, secondary, billing, shipping, etc.)
 * 
 * @property int $id
 * @property int $addressable_id
 * @property string $addressable_type
 * @property string $address_type
 * @property string|null $label
 * @property string $line1
 * @property string|null $line2
 * @property string $district
 * @property string|null $city
 * @property string $state_province
 * @property string $country
 * @property string $pin_zip_code
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_default
 * @property int $active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Address extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'addresses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'addressable_id',
        'addressable_type',
        'address_type',
        'label',
        'line1',
        'line2',
        'district',
        'city',
        'state_province',
        'country',
        'country_id',      // New: Geographic ID
        'state_id',        // New: Geographic ID
        'pin_zip_code',
        'latitude',
        'longitude',
        'is_default',
        'active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'integer',
        'country_id' => 'integer',
        'state_id' => 'integer',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
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
     * Address type constants
     */
    const TYPE_PRIMARY = 'primary';
    const TYPE_SECONDARY = 'secondary';
    const TYPE_BILLING = 'billing';
    const TYPE_SHIPPING = 'shipping';
    const TYPE_OFFICE = 'office';
    const TYPE_HOME = 'home';

    /**
     * Get the parent addressable model (User, Tenant, etc.).
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include active addresses.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1)->whereNull('deleted_at');
    }

    /**
     * Scope a query to only include inactive addresses.
     */
    public function scopeInactive($query)
    {
        return $query->where('active', 0);
    }

    /**
     * Scope a query by address type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('address_type', $type);
    }

    /**
     * Scope a query to only include default addresses.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query by country.
     */
    public function scopeInCountry($query, string $country)
    {
        return $query->where('country', $country);
    }

    /**
     * Scope a query by state/province.
     */
    public function scopeInState($query, string $state)
    {
        return $query->where('state_province', $state);
    }

    /**
     * Check if this is the primary address.
     */
    public function isPrimary(): bool
    {
        return $this->address_type === self::TYPE_PRIMARY;
    }

    /**
     * Check if this is the default address.
     */
    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Check if address is active.
     */
    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * Set this address as the default.
     * Unsets any other default addresses for the same entity and type.
     */
    public function setAsDefault(): bool
    {
        // First, unset all other default addresses for this entity/type
        self::where('addressable_id', $this->addressable_id)
            ->where('addressable_type', $this->addressable_type)
            ->where('address_type', $this->address_type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Then set this one as default
        return $this->update(['is_default' => true]);
    }

    /**
     * Get the full address as a formatted string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->district,
            $this->city,
            $this->state_province,
            $this->country . ' - ' . $this->pin_zip_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the short address (line1 + city).
     */
    public function getShortAddressAttribute(): string
    {
        return $this->line1 . ($this->city ? ', ' . $this->city : '');
    }

    /**
     * Check if address has geolocation coordinates.
     */
    public function hasGeolocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Get the address as an array suitable for display.
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->address_type,
            'label' => $this->label,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'district' => $this->district,
            'city' => $this->city,
            'state' => $this->state_province,
            'country' => $this->country,
            'pin_code' => $this->pin_zip_code,
            'is_default' => $this->is_default,
            'full_address' => $this->full_address,
        ];
    }

    /**
     * Get the country for this address.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get the state for this address.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // When creating an address, if is_default is true, 
        // unset other default addresses automatically
        static::creating(function ($address) {
            if ($address->is_default) {
                self::where('addressable_id', $address->addressable_id)
                    ->where('addressable_type', $address->addressable_type)
                    ->where('address_type', $address->address_type)
                    ->update(['is_default' => false]);
            }
        });

        // When updating an address to be default,
        // unset other default addresses automatically
        static::updating(function ($address) {
            if ($address->is_default && $address->isDirty('is_default')) {
                self::where('addressable_id', $address->addressable_id)
                    ->where('addressable_type', $address->addressable_type)
                    ->where('address_type', $address->address_type)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }
        });
    }
}
