<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Models\Tenant;

/**
 * Idempotent compact BCC catalog for demo parishes (exactly five communities).
 */
class TenantBccsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! DemoSeedGuard::assertEnvironmentAllowed($this->command)) {
            return;
        }

        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for BCC demo seeding.');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->compactCommunities() as $row) {
            $exists = BCC::query()
                ->where('tenant_id', $tenant->id)
                ->where('bcc_code', $row['bcc_code'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            BCC::query()->create(array_merge($row, [
                'tenant_id' => $tenant->id,
                'notes' => TenantDemoMarkers::MARKER.' '.CompactDemoMarkers::MARKER,
                'status' => 'active',
            ]));
            $created++;
        }

        $this->command?->info(sprintf(
            'Compact BCC demo: %d created, %d skipped (tenant #%d).',
            $created,
            $skipped,
            $tenant->id
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compactCommunities(): array
    {
        return [
            [
                'name' => 'Demo Community — North',
                'bcc_code' => 'DEMO-N',
                'meeting_day' => 'sunday',
                'meeting_time' => '10:00',
                'meeting_frequency' => 'Weekly',
                'description' => TenantDemoMarkers::MARKER.' north zone',
            ],
            [
                'name' => 'Demo Community — South',
                'bcc_code' => 'DEMO-S',
                'meeting_day' => 'saturday',
                'meeting_time' => '17:00',
                'meeting_frequency' => 'Weekly',
                'description' => TenantDemoMarkers::MARKER.' south zone',
            ],
            [
                'name' => 'Demo Community — East',
                'bcc_code' => 'DEMO-E',
                'meeting_day' => 'friday',
                'meeting_time' => '19:00',
                'meeting_frequency' => 'Bi-weekly',
                'description' => TenantDemoMarkers::MARKER.' east zone',
            ],
            [
                'name' => 'Demo Community — West',
                'bcc_code' => 'DEMO-W',
                'meeting_day' => 'thursday',
                'meeting_time' => '18:30',
                'meeting_frequency' => 'Weekly',
                'description' => TenantDemoMarkers::MARKER.' west zone',
            ],
            [
                'name' => 'Demo Community — Central',
                'bcc_code' => 'DEMO-C',
                'meeting_day' => 'wednesday',
                'meeting_time' => '19:30',
                'meeting_frequency' => 'Monthly',
                'description' => TenantDemoMarkers::MARKER.' central zone',
            ],
        ];
    }
}
