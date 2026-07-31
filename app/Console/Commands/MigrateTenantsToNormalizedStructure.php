<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Tenants\Models\Tenant;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Address;

class MigrateTenantsToNormalizedStructure extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate-to-normalized
                            {--dry-run : Run in dry-run mode without making changes}
                            {--tenant= : Migrate only a specific tenant by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing tenant data from denormalized to normalized structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting tenant data migration to normalized structure...');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $tenantId = $this->option('tenant');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE: No changes will be made to the database.');
            $this->newLine();
        }

        // Get tenants to migrate
        $query = Tenant::withTrashed();
        if ($tenantId) {
            $query->where('id', $tenantId);
        }
        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->error('❌ No tenants found to migrate.');
            return 1;
        }

        $this->info("📊 Found {$tenants->count()} tenant(s) to migrate.");
        $this->newLine();

        $stats = [
            'tenants_processed' => 0,
            'users_created' => 0,
            'addresses_created' => 0,
            'errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar($tenants->count());
        $progressBar->start();

        foreach ($tenants as $tenant) {
            try {
                if (!$dryRun) {
                    DB::beginTransaction();
                }

                $result = $this->migrateTenant($tenant, $dryRun);
                
                $stats['users_created'] += $result['users'];
                $stats['addresses_created'] += $result['addresses'];
                $stats['tenants_processed']++;

                if (!$dryRun) {
                    DB::commit();
                }

                $progressBar->advance();

            } catch (\Exception $e) {
                if (!$dryRun) {
                    DB::rollBack();
                }
                
                $stats['errors']++;
                $this->newLine(2);
                $this->error("❌ Error migrating tenant ID {$tenant->id}: " . $e->getMessage());
                $this->newLine();
                
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->displaySummary($stats, $dryRun);

        return $stats['errors'] > 0 ? 1 : 0;
    }

    /**
     * Migrate a single tenant to normalized structure.
     */
    private function migrateTenant(Tenant $tenant, bool $dryRun): array
    {
        $stats = ['users' => 0, 'addresses' => 0];

        // 1. Migrate Primary Contact User
        if ($tenant->primary_user_name && $tenant->primary_user_email) {
            $primaryUser = $this->createOrUpdateUser([
                'tenant_id' => $tenant->id,
                'name' => $tenant->primary_user_name,
                'email' => $tenant->primary_user_email,
                'contact_number' => $tenant->primary_contact_number,
                'user_type' => User::USER_TYPE_PRIMARY_CONTACT,  // 1 = primary_contact
                'password' => Hash::make('TempPassword123!'), // They'll need to reset
                'active' => 1,
            ], $dryRun);

            if ($primaryUser && !$dryRun) {
                $stats['users']++;

                // Migrate primary user address
                if ($tenant->primary_user_address && is_array($tenant->primary_user_address)) {
                    $this->createAddress($primaryUser, $tenant->primary_user_address, 'primary', $dryRun);
                    $stats['addresses']++;
                }
            }
        }

        // 2. Migrate Secondary Contact User
        if ($tenant->secondary_user_name && $tenant->secondary_user_email) {
            $secondaryUser = $this->createOrUpdateUser([
                'tenant_id' => $tenant->id,
                'name' => $tenant->secondary_user_name,
                'email' => $tenant->secondary_user_email,
                'contact_number' => $tenant->secondary_contact_number,
                'user_type' => User::USER_TYPE_SECONDARY_CONTACT,  // 2 = secondary_contact
                'password' => Hash::make('TempPassword123!'), // They'll need to reset
                'active' => 1,
            ], $dryRun);

            if ($secondaryUser && !$dryRun) {
                $stats['users']++;

                // Migrate secondary user address
                if ($tenant->secondary_user_address && is_array($tenant->secondary_user_address)) {
                    $this->createAddress($secondaryUser, $tenant->secondary_user_address, 'secondary', $dryRun);
                    $stats['addresses']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Create or update a user.
     */
    private function createOrUpdateUser(array $userData, bool $dryRun): ?User
    {
        if ($dryRun) {
            return new User($userData);
        }

        // Check if user already exists by email
        $user = User::where('email', $userData['email'])->first();

        if ($user) {
            // Update existing user
            $user->update(array_filter([
                'tenant_id' => $userData['tenant_id'],
                'name' => $userData['name'],
                'contact_number' => $userData['contact_number'],
                'user_type' => $userData['user_type'],
            ]));
        } else {
            // Create new user
            $user = User::create($userData);
        }

        return $user;
    }

    /**
     * Create an address for a user.
     */
    private function createAddress(User $user, array $addressData, string $type, bool $dryRun): ?Address
    {
        if ($dryRun) {
            return new Address();
        }

        // Check if address already exists
        $existingAddress = Address::where('addressable_type', 'User')
            ->where('addressable_id', $user->id)
            ->where('address_type', $type)
            ->first();

        if ($existingAddress) {
            // Update existing address
            $existingAddress->update([
                'line1' => $addressData['line1'] ?? '',
                'line2' => $addressData['line2'] ?? null,
                'district' => $addressData['district'] ?? '',
                'state_province' => $addressData['state_province'] ?? '',
                'country' => $addressData['country'] ?? '',
                'pin_zip_code' => $addressData['pin_zip_code'] ?? '',
                'active' => 1,
                'is_default' => true,
            ]);
            return $existingAddress;
        }

        // Create new address
        return Address::create([
            'addressable_id' => $user->id,
            'addressable_type' => 'User',
            'address_type' => $type,
            'line1' => $addressData['line1'] ?? '',
            'line2' => $addressData['line2'] ?? null,
            'district' => $addressData['district'] ?? '',
            'state_province' => $addressData['state_province'] ?? '',
            'country' => $addressData['country'] ?? '',
            'pin_zip_code' => $addressData['pin_zip_code'] ?? '',
            'active' => 1,
            'is_default' => true,
        ]);
    }

    /**
     * Display migration summary.
     */
    private function displaySummary(array $stats, bool $dryRun): void
    {
        $this->info('═══════════════════════════════════════════');
        $this->info('          MIGRATION SUMMARY');
        $this->info('═══════════════════════════════════════════');
        $this->newLine();

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN - No changes were made');
            $this->newLine();
        }

        $this->line("✅ Tenants Processed:  {$stats['tenants_processed']}");
        $this->line("👤 Users Created:      {$stats['users_created']}");
        $this->line("📍 Addresses Created:  {$stats['addresses_created']}");
        
        if ($stats['errors'] > 0) {
            $this->error("❌ Errors Encountered: {$stats['errors']}");
        } else {
            $this->info("❌ Errors:             0");
        }

        $this->newLine();
        
        if (!$dryRun && $stats['errors'] === 0) {
            $this->info('✅ Migration completed successfully!');
        } elseif ($dryRun) {
            $this->info('ℹ️  Run without --dry-run to perform actual migration.');
        }

        $this->newLine();
    }
}
