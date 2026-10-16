<?php

namespace Modules\Tenants\LoadTesting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

class LoadTestBenchmarkRunner
{
    /**
     * @param  array{
     *     tag: string,
     *     tenant_id?: int|null,
     *     warmup: int,
     *     iterations: int,
     *     count_queries: bool,
     * }  $options
     */
    public function run(array $options): LoadTestBenchmarkResult
    {
        $tenant = $this->resolveTenant($options['tag'], $options['tenant_id'] ?? null);
        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', 'like', 'load-test-'.$options['tag'].'-%@ekklesia.test')
            ->orderBy('id')
            ->firstOrFail();

        Passport::actingAs($user);

        $scenarios = config('tenants.load_test.benchmark.scenarios', []);
        $thresholds = config('tenants.load_test.benchmark.thresholds', []);
        $results = [];
        $passed = true;

        foreach ($scenarios as $key => $scenario) {
            $metrics = $this->benchmarkScenario(
                $scenario,
                (int) $options['warmup'],
                (int) $options['iterations'],
                (bool) $options['count_queries'],
            );

            $threshold = $thresholds[$key] ?? [];
            $scenarioPassed = $this->scenarioPasses($metrics, $threshold);
            $passed = $passed && $scenarioPassed;

            $results[$key] = array_merge($metrics, [
                'passed' => $scenarioPassed,
                'threshold_p95_ms' => $threshold['p95_ms'] ?? null,
                'threshold_max_queries' => $threshold['max_queries'] ?? null,
            ]);
        }

        return new LoadTestBenchmarkResult(
            tenantId: (int) $tenant->id,
            scenarios: $results,
            passed: $passed,
        );
    }

    private function resolveTenant(string $tag, ?int $tenantId): Tenant
    {
        if ($tenantId !== null) {
            return Tenant::query()->findOrFail($tenantId);
        }

        return Tenant::query()
            ->where('settings->load_test_tag', $tag)
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * @param  array{method: string, path: string, query?: array<string, mixed>}  $scenario
     * @return array{p50_ms: float, p95_ms: float, max_ms: float, min_ms: float, avg_ms: float, errors: int, query_p95: ?int}
     */
    private function benchmarkScenario(array $scenario, int $warmup, int $iterations, bool $countQueries): array
    {
        $durations = [];
        $queryCounts = [];
        $errors = 0;

        $totalRuns = $warmup + $iterations;

        for ($run = 0; $run < $totalRuns; $run++) {
            if ($countQueries) {
                DB::flushQueryLog();
                DB::enableQueryLog();
            }

            $started = microtime(true);
            $response = $this->dispatch($scenario);
            $elapsedMs = (microtime(true) - $started) * 1000;

            if ($run < $warmup) {
                continue;
            }

            if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                $errors++;
            }

            $durations[] = $elapsedMs;

            if ($countQueries) {
                $queryCounts[] = count(DB::getQueryLog());
            }
        }

        sort($durations);

        return [
            'p50_ms' => $this->percentile($durations, 50),
            'p95_ms' => $this->percentile($durations, 95),
            'max_ms' => (float) max($durations),
            'min_ms' => (float) min($durations),
            'avg_ms' => array_sum($durations) / max(1, count($durations)),
            'errors' => $errors,
            'query_p95' => $queryCounts === [] ? null : (int) $this->percentile($queryCounts, 95),
        ];
    }

    /**
     * @param  array{method: string, path: string, query?: array<string, mixed>}  $scenario
     */
    private function dispatch(array $scenario): Response
    {
        $query = $scenario['query'] ?? [];
        $path = $scenario['path'];

        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }

        $request = Request::create(
            $path,
            strtoupper($scenario['method']),
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        /** @var Response $response */
        $response = app()->handle($request);
        app()->terminate();

        return $response;
    }

    /**
     * @param  list<float|int>  $values
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (float) $values[$index];
    }

    /**
     * @param  array{p50_ms: float, p95_ms: float, max_ms: float, min_ms: float, avg_ms: float, errors: int, query_p95: ?int}  $metrics
     * @param  array{p95_ms?: int, max_queries?: int}  $threshold
     */
    private function scenarioPasses(array $metrics, array $threshold): bool
    {
        if ($metrics['errors'] > 0) {
            return false;
        }

        if (isset($threshold['p95_ms']) && $metrics['p95_ms'] > $threshold['p95_ms']) {
            return false;
        }

        if (
            isset($threshold['max_queries'], $metrics['query_p95'])
            && $metrics['query_p95'] > $threshold['max_queries']
        ) {
            return false;
        }

        return true;
    }
}
