<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Database\Seeders\SacredHeartChurchBccsSeeder;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Models\Tenant;

/**
 * Idempotent BCC catalog for demo parishes (Sacred Heart catalog or generic communities).
 */
class TenantBccsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for BCC demo seeding.');

            return;
        }

        if ($this->isSacredHeartTenant($tenant)) {
            $this->call(SacredHeartChurchBccsSeeder::class);

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->genericCommunities() as $row) {
            $exists = BCC::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $row['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            BCC::query()->create(array_merge($row, [
                'tenant_id' => $tenant->id,
                'notes' => TenantDemoMarkers::MARKER,
                'status' => 'active',
            ]));
            $created++;
        }

        $this->command?->info(sprintf(
            'Generic BCC demo: %d created, %d skipped (tenant #%d).',
            $created,
            $skipped,
            $tenant->id
        ));
    }

    private function isSacredHeartTenant(Tenant $tenant): bool
    {
        $name = strtolower($tenant->name);

        return str_contains($name, 'sacred heart');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function genericCommunities(): array
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
            [
                'name' => 'Demo Community — Outreach',
                'bcc_code' => 'DEMO-O',
                'meeting_day' => 'tuesday',
                'meeting_time' => '20:00',
                'meeting_frequency' => 'Weekly',
                'description' => TenantDemoMarkers::MARKER.' outreach',
            ],
        ];
    }
}
