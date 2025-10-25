<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class AssignPermissionsToAdministrators extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:assign-admin-permissions 
                            {--tenant-id= : Assign permissions to a specific tenant (optional)}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign all system permissions to tenant Administrator roles';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔐 Assigning Permissions to Administrator Roles');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $tenantId = $this->option('tenant-id');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
            $this->line('');
        }

        try {
            // Get all system permissions (active, not custom, global)
            $systemPermissions = Permission::where('active', 1)
                ->where('is_custom', false)
                ->whereNull('tenant_id')
                ->get();

            if ($systemPermissions->isEmpty()) {
                $this->error('❌ No system permissions found!');
                $this->warn('Please run: php artisan db:seed --class="Modules\\RolesAndPermissions\\Database\\Seeders\\PermissionsSeeder"');
                return Command::FAILURE;
            }

            $this->info("Found {$systemPermissions->count()} system permissions to assign");
            $this->line('');

            // Get Administrator roles
            $query = Role::where('name', 'Administrator')
                ->whereNotNull('tenant_id');

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $adminRoles = $query->with('tenant')->get();

            if ($adminRoles->isEmpty()) {
                $this->warn('⚠️  No Administrator roles found');
                return Command::SUCCESS;
            }

            $this->info("Found {$adminRoles->count()} Administrator role(s) to update");
            $this->line('');

            $successCount = 0;
            $failureCount = 0;
            $skippedCount = 0;

            foreach ($adminRoles as $role) {
                $tenantName = $role->tenant->name ?? "Tenant #{$role->tenant_id}";
                
                // Get current permissions count
                $currentPermissionsCount = $role->permissions()->count();
                
                $this->line("Processing: <fg=cyan>{$tenantName}</> (Role ID: {$role->id})");
                $this->line("  Current permissions: <fg=yellow>{$currentPermissionsCount}</>/{$systemPermissions->count()}");

                if ($currentPermissionsCount === $systemPermissions->count()) {
                    $this->line("  Status: <fg=green>Already has all permissions - Skipping</>");
                    $skippedCount++;
                    $this->line('');
                    continue;
                }

                if (!$dryRun) {
                    try {
                        // Assign all permissions
                        $permissionIds = $systemPermissions->pluck('id')->toArray();
                        $role->permissions()->sync($permissionIds);
                        
                        $this->line("  Status: <fg=green>✅ Successfully assigned {$systemPermissions->count()} permissions</>");
                        
                        Log::info('Permissions assigned to Administrator role via command', [
                            'role_id' => $role->id,
                            'tenant_id' => $role->tenant_id,
                            'tenant_name' => $tenantName,
                            'permissions_count' => $systemPermissions->count(),
                        ]);
                        
                        $successCount++;
                    } catch (\Exception $e) {
                        $this->line("  Status: <fg=red>❌ Failed: {$e->getMessage()}</>");
                        
                        Log::error('Failed to assign permissions to Administrator role', [
                            'role_id' => $role->id,
                            'tenant_id' => $role->tenant_id,
                            'error' => $e->getMessage(),
                        ]);
                        
                        $failureCount++;
                    }
                } else {
                    $permissionsToAdd = $systemPermissions->count() - $currentPermissionsCount;
                    $this->line("  Status: <fg=yellow>Would assign {$permissionsToAdd} new permission(s)</>");
                }

                $this->line('');
            }

            // Summary
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('📊 Summary:');
            $this->line('');
            
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Administrator Roles', $adminRoles->count()],
                    ['Successfully Updated', $successCount],
                    ['Skipped (Already Complete)', $skippedCount],
                    ['Failed', $failureCount],
                    ['Permissions per Role', $systemPermissions->count()],
                ]
            );

            if (!$dryRun) {
                if ($failureCount > 0) {
                    $this->warn("⚠️  Some roles failed to update. Check logs for details.");
                    return Command::FAILURE;
                }
                
                $this->info('✅ All Administrator roles updated successfully!');
            } else {
                $this->info('ℹ️  This was a dry run. Run without --dry-run to apply changes.');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Command failed: ' . $e->getMessage());
            Log::error('AssignPermissionsToAdministrators command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

