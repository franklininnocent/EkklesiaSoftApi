<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\LoadTesting\LoadTestBenchmarkRunner;

class RunLoadTestBenchmark extends Command
{
    protected $signature = 'tenants:load-test-benchmark
        {--tag= : Fixture tag to benchmark (default from config)}
        {--tenant-id= : Specific tenant id (defaults to first tagged tenant)}
        {--warmup= : Warmup iterations (default from config)}
        {--iterations= : Measured iterations (default from config)}
        {--count-queries : Track SQL query counts per scenario}';

    protected $description = 'Benchmark hot API paths against seeded load-test fixtures.';

    public function handle(LoadTestBenchmarkRunner $runner): int
    {
        config([
            'tenants.api.rate_limit.enabled' => false,
        ]);

        $tag = (string) ($this->option('tag') ?: config('tenants.load_test.tag', 'load-test'));
        $tenantId = $this->option('tenant-id');
        $warmup = (int) ($this->option('warmup') ?: config('tenants.load_test.benchmark.warmup', 5));
        $iterations = (int) ($this->option('iterations') ?: config('tenants.load_test.benchmark.iterations', 30));
        $countQueries = (bool) $this->option('count-queries');

        $this->info(sprintf('Running load-test benchmark (tag=%s, warmup=%d, iterations=%d)', $tag, $warmup, $iterations));

        $result = $runner->run([
            'tag' => $tag,
            'tenant_id' => $tenantId !== null ? (int) $tenantId : null,
            'warmup' => $warmup,
            'iterations' => $iterations,
            'count_queries' => $countQueries,
        ]);

        $rows = [];
        foreach ($result->scenarios as $key => $metrics) {
            $rows[] = [
                $key,
                number_format($metrics['p50_ms'], 1),
                number_format($metrics['p95_ms'], 1),
                number_format($metrics['max_ms'], 1),
                $metrics['errors'],
                $metrics['query_p95'] ?? '—',
                $metrics['passed'] ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(
            ['Scenario', 'p50 ms', 'p95 ms', 'max ms', 'errors', 'query p95', 'result'],
            $rows,
        );

        if (! $result->passed) {
            $this->error('Benchmark thresholds not met.');

            return self::FAILURE;
        }

        $this->info('All benchmark thresholds passed.');

        return self::SUCCESS;
    }
}
