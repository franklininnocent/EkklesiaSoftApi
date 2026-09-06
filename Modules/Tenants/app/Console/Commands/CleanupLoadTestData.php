<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\LoadTesting\LoadTestFixtureBuilder;

class CleanupLoadTestData extends Command
{
    protected $signature = 'tenants:cleanup-load-test-data
        {--tag= : Fixture tag to purge (default from config)}
        {--dry-run : Preview purge without deleting}
        {--force : Skip confirmation}';

    protected $description = 'Remove tenants and cascaded data seeded for load testing.';

    public function handle(LoadTestFixtureBuilder $builder): int
    {
        $tag = (string) ($this->option('tag') ?: config('tenants.load_test.tag', 'load-test'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $count = \Modules\Tenants\Models\Tenant::query()
            ->where('settings->load_test_tag', $tag)
            ->count();

        if ($count === 0) {
            $this->warn('No tenants found for tag: '.$tag);

            return self::SUCCESS;
        }

        $this->warn(sprintf('Will purge %d tenant(s) tagged "%s".', $count, $tag));

        if ($dryRun) {
            $this->comment('Dry run only — no data deleted.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Delete load-test tenants and all cascaded data?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $deleted = $builder->purge($tag);
        $this->info(sprintf('Purged %d tenant(s).', $deleted));

        return self::SUCCESS;
    }
}
