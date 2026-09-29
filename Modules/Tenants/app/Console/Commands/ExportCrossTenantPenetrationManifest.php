<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Tenants\LoadTesting\LoadTestFixtureBuilder;
use Modules\Tenants\Penetration\CrossTenantPenetrationFixtureSeeder;
use Modules\Tenants\Models\Tenant;

class ExportCrossTenantPenetrationManifest extends Command
{
    protected $signature = 'tenants:export-penetration-manifest
        {--tag=penetration : Tag prefix for seeded tenants}
        {--output= : Output JSON path (default storage/penetration/manifest-{tag}.json)}
        {--force : Skip confirmation}';

    protected $description = 'Seed attacker + victim tenants and export Playwright/API probe manifest.';

    public function handle(
        LoadTestFixtureBuilder $loadTestBuilder,
        CrossTenantPenetrationFixtureSeeder $fixtureSeeder,
    ): int {
        $tag = (string) $this->option('tag');
        $output = (string) ($this->option('output') ?: storage_path('penetration/manifest-'.$tag.'.json'));
        $force = (bool) $this->option('force');

        if (! $force && $this->input->isInteractive() && ! $this->confirm('Seed penetration tenants in the current database?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $attacker = $loadTestBuilder->seed([
            'tag' => $tag.'-attacker',
            'tenants' => 1,
            'members' => 3,
            'members_per_family' => 3,
            'chunk_size' => 100,
            'export_tokens' => true,
        ]);

        $victimTenant = Tenant::factory()->active()->create([
            'name' => 'Penetration Victim Parish',
            'slug' => 'penetration-victim-'.$tag,
            'settings' => ['penetration_tag' => $tag.'-victim'],
            'features' => ['donations', 'events', 'groups'],
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);

        $victim = $fixtureSeeder->seedVictimTenant($victimTenant);

        $manifest = [
            'tag' => $tag,
            'base_url' => rtrim((string) config('app.url'), '/'),
            'attacker' => $attacker->tenants[0],
            'victim' => [
                'tenant_id' => $victimTenant->id,
                'family_id' => $victim->family->id,
                'person_id' => $victim->person->id,
                'sacrament_id' => $victim->sacrament->id,
                'payment_id' => $victim->payment->id,
                'pastoral_request_id' => $victim->pastoralRequest->id,
                'bcc_id' => $victim->bcc->id,
                'export_id' => $victim->export->id,
            ],
            'playwright' => [
                'CROSS_TENANT_FOREIGN_FAMILY_ID' => $victim->family->id,
            ],
        ];

        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode($manifest, JSON_PRETTY_PRINT));

        $this->info('Manifest: '.$output);
        $this->line('export CROSS_TENANT_FOREIGN_FAMILY_ID='.$victim->family->id);

        return self::SUCCESS;
    }
}
