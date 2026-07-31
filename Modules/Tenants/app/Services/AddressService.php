<?php

namespace Modules\Tenants\Services;

use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddressService
{
    /**
     * Create a new address for an entity.
     *
     * @param Model $entity The entity (User, Tenant, etc.) that owns the address
     * @param array $addressData The address data
     * @param string $type Address type (primary, secondary, billing, shipping, official)
     * @param bool $setAsDefault Whether to set this as the default address
     * @return Address|null
     */
    public function create(Model $entity, array $addressData, string $type = 'primary', bool $setAsDefault = false): ?Address
    {
        try {
            DB::beginTransaction();

            // Fetch country and state names from IDs for backwards compatibility
            $countryName = null;
            $stateName = null;
            
            if (isset($addressData['country_id'])) {
                $country = Country::find($addressData['country_id']);
                $countryName = $country?->name;
            }
            
            if (isset($addressData['state_id'])) {
                $state = State::find($addressData['state_id']);
                $stateName = $state?->name;
            }

            // Prepare address data
            $data = array_merge($addressData, [
                'addressable_id' => $entity->id,
                'addressable_type' => get_class($entity),
                'address_type' => $type,
                'is_default' => $setAsDefault,
                'active' => $addressData['active'] ?? 1,
                
                // Store old string fields for backwards compatibility
                'country' => $countryName,
                'state_province' => $stateName,
                // district remains as-is from input (still a string field)
            ]);

            // If setting as default, unset other defaults
            if ($setAsDefault) {
                $this->unsetDefaults($entity, $type);
            }

            // Create address
            $address = Address::create($data);

            DB::commit();

            Log::info('Address created successfully', [
                'address_id' => $address->id,
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id,
                'type' => $type,
                'country_id' => $addressData['country_id'] ?? null,
                'state_id' => $addressData['state_id'] ?? null,
            ]);

            return $address;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating address', [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing address.
     *
     * @param Address $address The address to update
     * @param array $addressData The new address data
     * @param bool $setAsDefault Whether to set this as the default address
     * @return Address|null
     */
    public function update(Address $address, array $addressData, bool $setAsDefault = false): ?Address
    {
        try {
            DB::beginTransaction();

            // Fetch country and state names from IDs for backwards compatibility
            if (isset($addressData['country_id'])) {
                $country = Country::find($addressData['country_id']);
                $addressData['country'] = $country?->name;
            }
            
            if (isset($addressData['state_id'])) {
                $state = State::find($addressData['state_id']);
                $addressData['state_province'] = $state?->name;
            }

            // If setting as default, unset other defaults
            if ($setAsDefault && !$address->is_default) {
                $this->unsetDefaults($address->addressable, $address->address_type);
                $addressData['is_default'] = true;
            }

            // Update address
            $address->update($addressData);

            DB::commit();

            Log::info('Address updated successfully', [
                'address_id' => $address->id,
                'country_id' => $addressData['country_id'] ?? null,
                'state_id' => $addressData['state_id'] ?? null,
            ]);

            return $address->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating address', [
                'address_id' => $address->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete an address (soft delete).
     *
     * @param Address $address The address to delete
     * @return bool
     */
    public function delete(Address $address): bool
    {
        try {
            $addressId = $address->id;
            $wasDefault = $address->is_default;
            $entity = $address->addressable;
            $type = $address->address_type;

            $address->delete();

            // If this was the default, set another one as default
            if ($wasDefault && $entity) {
                $this->autoSetDefaultAddress($entity, $type);
            }

            Log::info('Address deleted successfully', [
                'address_id' => $addressId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error deleting address', [
                'address_id' => $address->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Set an address as the default for its type.
     *
     * @param Address $address The address to set as default
     * @return Address
     */
    public function setAsDefault(Address $address): Address
    {
        try {
            DB::beginTransaction();

            // Unset other defaults
            $this->unsetDefaults($address->addressable, $address->address_type);

            // Set this as default
            $address->update(['is_default' => true]);

            DB::commit();

            Log::info('Address set as default', [
                'address_id' => $address->id,
            ]);

            return $address->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error setting address as default', [
                'address_id' => $address->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get addresses for an entity by type.
     *
     * @param Model $entity The entity that owns the addresses
     * @param string|null $type Optional address type filter
     * @param bool $activeOnly Whether to return only active addresses
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAddressesForEntity(Model $entity, ?string $type = null, bool $activeOnly = true)
    {
        $query = $entity->addresses();

        if ($type) {
            $query->where('address_type', $type);
        }

        if ($activeOnly) {
            $query->where('active', 1);
        }

        return $query->get();
    }

    /**
     * Get the default address for an entity by type.
     *
     * @param Model $entity The entity that owns the address
     * @param string $type Address type
     * @return Address|null
     */
    public function getDefaultAddress(Model $entity, string $type = 'primary'): ?Address
    {
        return $entity->addresses()
            ->where('address_type', $type)
            ->where('is_default', true)
            ->where('active', 1)
            ->first();
    }

    /**
     * Bulk create addresses for an entity.
     *
     * @param Model $entity The entity that owns the addresses
     * @param array $addressesData Array of address data
     * @return array Array of created addresses
     */
    public function bulkCreate(Model $entity, array $addressesData): array
    {
        $createdAddresses = [];

        try {
            DB::beginTransaction();

            foreach ($addressesData as $addressData) {
                $type = $addressData['address_type'] ?? 'primary';
                $setAsDefault = $addressData['is_default'] ?? false;

                $address = $this->create($entity, $addressData, $type, $setAsDefault);
                if ($address) {
                    $createdAddresses[] = $address;
                }
            }

            DB::commit();

            return $createdAddresses;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in bulk address creation', [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create or update an address.
     * If an address ID is provided, update it. Otherwise, create a new one.
     *
     * @param Model $entity The entity that owns the address
     * @param array $addressData The address data (may include 'id')
     * @param string $type Address type
     * @param bool $setAsDefault Whether to set as default
     * @return Address
     */
    public function createOrUpdate(Model $entity, array $addressData, string $type = 'primary', bool $setAsDefault = false): Address
    {
        if (isset($addressData['id']) && $addressData['id']) {
            $address = Address::findOrFail($addressData['id']);
            return $this->update($address, $addressData, $setAsDefault);
        }

        return $this->create($entity, $addressData, $type, $setAsDefault);
    }

    /**
     * Unset default flag for all addresses of a specific type for an entity.
     *
     * @param Model $entity The entity that owns the addresses
     * @param string $type Address type
     * @return void
     */
    private function unsetDefaults(Model $entity, string $type): void
    {
        $entity->addresses()
            ->where('address_type', $type)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Automatically set the first active address as default if none exists.
     *
     * @param Model $entity The entity that owns the addresses
     * @param string $type Address type
     * @return void
     */
    private function autoSetDefaultAddress(Model $entity, string $type): void
    {
        $firstAddress = $entity->addresses()
            ->where('address_type', $type)
            ->where('active', 1)
            ->orderBy('created_at', 'asc')
            ->first();

        if ($firstAddress) {
            $firstAddress->update(['is_default' => true]);
        }
    }

    /**
     * Validate address data structure.
     *
     * @param array $addressData The address data to validate
     * @return bool
     */
    public function validateAddressData(array $addressData): bool
    {
        $requiredFields = ['line1', 'district', 'state_province', 'country', 'pin_zip_code'];

        foreach ($requiredFields as $field) {
            if (empty($addressData[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format address for display.
     *
     * @param Address $address The address to format
     * @param string $format Format type: 'full', 'short', 'oneline'
     * @return string
     */
    public function formatAddress(Address $address, string $format = 'full'): string
    {
        switch ($format) {
            case 'short':
                return $address->short_address;
            
            case 'oneline':
                $parts = array_filter([
                    $address->line1,
                    $address->district,
                    $address->state_province,
                    $address->pin_zip_code,
                ]);
                return implode(', ', $parts);
            
            case 'full':
            default:
                return $address->full_address;
        }
    }

    /**
     * Get address statistics for an entity.
     *
     * @param Model $entity The entity to get stats for
     * @return array
     */
    public function getAddressStats(Model $entity): array
    {
        return [
            'total' => $entity->addresses()->count(),
            'active' => $entity->addresses()->where('active', 1)->count(),
            'inactive' => $entity->addresses()->where('active', 0)->count(),
            'by_type' => $entity->addresses()
                ->select('address_type', DB::raw('count(*) as count'))
                ->groupBy('address_type')
                ->pluck('count', 'address_type')
                ->toArray(),
        ];
    }

    /**
     * Deactivate an address without deleting it.
     *
     * @param Address $address The address to deactivate
     * @return Address
     */
    public function deactivate(Address $address): Address
    {
        $address->update(['active' => 0]);

        // If this was the default, set another one as default
        if ($address->is_default && $address->addressable) {
            $this->autoSetDefaultAddress($address->addressable, $address->address_type);
        }

        Log::info('Address deactivated', ['address_id' => $address->id]);

        return $address->fresh();
    }

    /**
     * Activate an address.
     *
     * @param Address $address The address to activate
     * @return Address
     */
    public function activate(Address $address): Address
    {
        $address->update(['active' => 1]);

        Log::info('Address activated', ['address_id' => $address->id]);

        return $address->fresh();
    }
}

