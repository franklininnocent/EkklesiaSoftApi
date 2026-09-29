<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Tenants\LoadTesting\LoadTestFixtureBuilder;

class SeedLoadTestData extends Command
{
    protected $signature = 'tenants:seed-load-test-data
        {--tenants= : Number of tenants to create (default from config)}
        {--members= : Total members across all tenants (default from config)}
        {--members-per-family= : Members per family (default from config)}
        {--tag= : Fixture tag stored in tenant settings (default from config)}
        {--chunk-size= : Bulk insert chunk size (default from config)}
        {--export-tokens : Write bearer tokens to storage/load-test/tokens-{tag}.json}
        {--dry-run : Preview the seed plan without writing}
        {--force : Required for large seeds (>10k members)}';

    protected $description = 'Seed bulk parish data for Phase 8 load testing (tenants, families, members).';

    public function handle(LoadTestFixtureBuilder $builder): int
    {
        $tenantCount = (int) ($this->option('tenants') ?: config('tenants.load_test.default_tenants', 100));
        $memberCount = (int) ($this->option('members') ?: config('tenants.load_test.default_members', 500000));
        $membersPerFamily = (int) ($this->option('members-per-family') ?: config('tenants.load_test.members_per_family', 5));
        $tag = (string) ($this->option('tag') ?: config('tenants.load_test.tag', 'load-test'));
        $chunkSize = (int) ($this->option('chunk-size') ?: config('tenants.load_test.chunk_size', 2000));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $exportTokens = (bool) $this->option('export-tokens');

        $membersPerTenant = (int) ceil($memberCount / max(1, $tenantCount));
        $familiesPerTenant = (int) ceil($membersPerTenant / max(1, $membersPerFamily));

        $this->info('Load test fixture plan');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Tag', $tag],
                ['Tenants', (string) $tenantCount],
                ['Total members', (string) $memberCount],
                ['Members / tenant (approx)', (string) $membersPerTenant],
                ['Families / tenant (approx)', (string) $familiesPerTenant],
                ['Members / family', (string) $membersPerFamily],
                ['Chunk size', (string) $chunkSize],
                ['Export tokens', $exportTokens ? 'yes' : 'no'],
            ],
        );

        if ($dryRun) {
            $this->comment('Dry run only — no data written.');

            return self::SUCCESS;
        }

        if ($memberCount > 10000 && ! $force) {
            $this->error('Refusing to seed more than 10,000 members without --force.');

            return self::FAILURE;
        }

        if (! $force && $this->input->isInteractive() && ! $this->confirm('Proceed with seeding?', true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $result = $builder->seed([
            'tag' => $tag,
            'tenants' => $tenantCount,
            'members' => $memberCount,
            'members_per_family' => $membersPerFamily,
            'chunk_size' => $chunkSize,
            'export_tokens' => $exportTokens,
        ]);

        $this->info(sprintf(
            'Seeded %d tenants, %d families, %d members in %.1fs.',
            $result->tenantCount,
            $result->familyCount,
            $result->memberCount,
            $result->elapsedSeconds,
        ));

        if ($exportTokens) {
            $path = storage_path('load-test/tokens-'.$tag.'.json');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode([
                'tag' => $tag,
                'base_url' => rtrim((string) config('app.url'), '/'),
                'tenants' => $result->tenants,
            ], JSON_PRETTY_PRINT));
            $this->info('Tokens written to '.$path);
        }

        return self::SUCCESS;
    }
}
