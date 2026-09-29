<?php

namespace Modules\MinistriesAssociations\Console\Commands;

use Illuminate\Console\Command;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\Tenants\Models\Tenant;

class BackfillMinistriesAssociationsDefaults extends Command
{
    protected $signature = 'ministries:seed-defaults
        {--tenant-id= : Seed a specific tenant only}
        {--dry-run : Preview which tenants would be processed without writing}';

    protected $description = 'Idempotently seed Ministries & Associations defaults for all (or one) tenants.';

    public function handle(MinistriesAssociationsDefaultSeeder $seeder): int
    {
        $tenantId = $this->option('tenant-id');
        $dryRun = (bool) $this->option('dry-run');

        $tenantQuery = Tenant::query()->orderBy('id');
        if ($tenantId !== null && $tenantId !== '') {
            $tenantQuery->where('id', $tenantId);
        }

        $tenants = $tenantQuery->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found for Ministries & Associations defaults seeding.');

            return self::SUCCESS;
        }

        $totals = [
            'tenants' => $tenants->count(),
            'categories_created' => 0,
            'types_created' => 0,
            'positions_created' => 0,
            'organizations_created' => 0,
            'categories_reused' => 0,
            'types_reused' => 0,
            'positions_reused' => 0,
            'organizations_reused' => 0,
        ];

        foreach ($tenants as $tenant) {
            if ($dryRun) {
                $this->line("Would seed Ministries defaults for tenant #{$tenant->id} ({$tenant->name}).");
                continue;
            }

            $summary = $seeder->run((int) $tenant->id, null);

            foreach ($summary as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }

            $this->info("Seeded Ministries defaults for tenant #{$tenant->id} ({$tenant->name}).");
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Tenants processed', $totals['tenants']],
                ['Categories created', $totals['categories_created']],
                ['Categories reused', $totals['categories_reused']],
                ['Types created', $totals['types_created']],
                ['Types reused', $totals['types_reused']],
                ['Positions created', $totals['positions_created']],
                ['Positions reused', $totals['positions_reused']],
                ['Organizations created', $totals['organizations_created']],
                ['Organizations reused', $totals['organizations_reused']],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run complete. No data was modified.');
        } else {
            $this->info('Ministries & Associations defaults seeding completed.');
        }

        return self::SUCCESS;
    }
}
