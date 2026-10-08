<?php

/**
 * Before/after overview load comparison. Run: php scripts/compare-dashboard-perf.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Tenants\Services\TenantExecutiveDashboardComposer;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array']);

$user = User::query()->whereNotNull('tenant_id')->where('active', 1)->orderBy('id')->first();
if (! $user) {
    fwrite(STDERR, "No tenant user found.\n");
    exit(1);
}

$tenantId = (int) $user->tenant_id;
auth()->setUser($user);

echo "tenant_id={$tenantId} user_id={$user->id}\n";
echo "uncached composer (buildUncached; response cache bypassed)\n\n";

$queries = [];
DB::listen(function ($q) use (&$queries) {
    $queries[] = $q->time;
});

function timed(callable $fn): array
{
    global $queries;
    $queries = [];
    $t0 = hrtime(true);
    $value = $fn();
    $ms = (hrtime(true) - $t0) / 1e6;

    return [$ms, count($queries), array_sum($queries), $value];
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

function runTimes(int $n, callable $fn): array
{
    $samples = [];
    for ($i = 0; $i < $n; $i++) {
        $samples[] = $fn();
    }

    return $samples;
}

$composerBuild = (new ReflectionClass(TenantExecutiveDashboardComposer::class))->getMethod('buildUncached');
$composerBuild->setAccessible(true);

$before = static function () use ($composerBuild, $user, $tenantId): array {
    $c = app(TenantExecutiveDashboardComposer::class);
    [$pMs, $pQ, $pSql] = timed(fn () => $composerBuild->invoke($c, $user, $tenantId, 'primary'));
    $c2 = app(TenantExecutiveDashboardComposer::class);
    [$sMs, $sQ, $sSql] = timed(fn () => $composerBuild->invoke($c2, $user, $tenantId, 'secondary'));

    return [
        'primary_ms' => $pMs,
        'secondary_ms' => $sMs,
        'sequential_ms' => $pMs + $sMs,
        'parallel_wall_ms' => max($pMs, $sMs),
        'queries' => $pQ + $sQ,
        'sql_ms' => $pSql + $sSql,
        'first_paint_ms' => $pMs,
        'label' => 'before',
    ];
};

$afterBundles = ['snapshot', 'celebrations', 'quick_actions', 'stewardship', 'mass', 'pastoral'];

$after = static function () use ($composerBuild, $user, $tenantId, $afterBundles): array {
    $times = [];
    $queries = 0;
    $sqlMs = 0;
    foreach ($afterBundles as $bundle) {
        $c = app(TenantExecutiveDashboardComposer::class);
        [$ms, $q, $sql] = timed(fn () => $composerBuild->invoke($c, $user, $tenantId, $bundle));
        $times[$bundle] = $ms;
        $queries += $q;
        $sqlMs += $sql;
    }

    return [
        'sections' => $times,
        'sequential_ms' => array_sum($times),
        'parallel_wall_ms' => max($times),
        'queries' => $queries,
        'sql_ms' => $sqlMs,
        'first_paint_ms' => min($times),
        'shell_ms' => 0.0,
        'label' => 'after',
    ];
};

$runs = 5;
echo "warmup discarded\n";
$before();
$after();
echo "=== BEFORE (UI waited on primary; secondary included unused attention) ===\n";
$beforeRuns = runTimes($runs, $before);
foreach ($beforeRuns as $i => $row) {
    echo sprintf(
        "  run %d: primary=%.1fms secondary=%.1fms sequential=%.1fms parallel_http_wall=%.1fms queries=%d sql=%.1fms first_overview_paint=%.1fms (waited for primary)\n",
        $i + 1,
        $row['primary_ms'],
        $row['secondary_ms'],
        $row['sequential_ms'],
        $row['parallel_wall_ms'],
        $row['queries'],
        $row['sql_ms'],
        $row['first_paint_ms']
    );
}

echo "\n=== AFTER (6 independent APIs; overview does not call attention) ===\n";
$afterRuns = runTimes($runs, $after);
foreach ($afterRuns as $i => $row) {
    $parts = [];
    foreach ($row['sections'] as $name => $ms) {
        $parts[] = sprintf('%s=%.1f', $name, $ms);
    }
    echo sprintf(
        "  run %d: %s sequential_sum=%.1fms parallel_http_wall=%.1fms queries=%d sql=%.1fms first_section_ready=%.1fms\n",
        $i + 1,
        implode(' ', $parts),
        $row['sequential_ms'],
        $row['parallel_wall_ms'],
        $row['queries'],
        $row['sql_ms'],
        $row['first_paint_ms']
    );
}

$bWall = array_map(fn ($r) => $r['parallel_wall_ms'], $beforeRuns);
$aWall = array_map(fn ($r) => $r['parallel_wall_ms'], $afterRuns);
$bPaint = array_map(fn ($r) => $r['first_paint_ms'], $beforeRuns);
$aPaint = array_map(fn ($r) => $r['first_paint_ms'], $afterRuns);
$bQ = array_map(fn ($r) => $r['queries'], $beforeRuns);
$aQ = array_map(fn ($r) => $r['queries'], $afterRuns);

echo "\n=== MEDIAN (n={$runs}, cold cache) ===\n";
echo sprintf("Before HTTP wall (max primary, secondary):     %.1f ms\n", median($bWall));
echo sprintf("After  HTTP wall (max of 6 independent):       %.1f ms\n", median($aWall));
echo sprintf("Before first overview paint (blocked on primary): %.1f ms\n", median($bPaint));
echo sprintf("After  first section ready (shell is immediate): %.1f ms composer; UI shell 0 ms wait\n", median($aPaint));
$bSnap = array_map(fn ($r) => $r['first_paint_ms'], $beforeRuns);
$aPeople = array_map(fn ($r) => $r['sections']['snapshot'], $afterRuns);
$aFinance = array_map(fn ($r) => $r['sections']['stewardship'], $afterRuns);
echo sprintf("People block ready before (primary): %.1f ms\n", median($bSnap));
echo sprintf("People block ready after (snapshot): %.1f ms\n", median($aPeople));
echo sprintf("Finance block ready before (secondary, includes attention): %.1f ms\n", median($bWall));
echo sprintf("Finance block ready after (stewardship only): %.1f ms\n", median($aFinance));
echo sprintf("Before query count: %d\n", (int) median($bQ));
echo sprintf("After  query count: %d\n", (int) median($aQ));

$wallDrop = median($bWall) - median($aWall);
$paintDrop = median($bPaint) - median($aPaint);
echo sprintf("HTTP wall improvement: %.1f ms (%.0f%%)\n", $wallDrop, median($bWall) > 0 ? 100 * $wallDrop / median($bWall) : 0);
echo sprintf("First overview content improvement: %.1f ms (%.0f%% vs waiting for primary)\n", $paintDrop, median($bPaint) > 0 ? 100 * $paintDrop / median($bPaint) : 0);

$apiBase = getenv('DASHBOARD_PERF_API') ?: 'http://127.0.0.1:8000/api';
echo "\n=== HTTP (curl) against {$apiBase} ===\n";

$token = null;
try {
    if (method_exists($user, 'createToken')) {
        $created = $user->createToken('dashboard-perf');
        $token = $created->accessToken ?? null;
    }
} catch (Throwable $e) {
    echo 'token create failed: '.$e->getMessage()."\n";
}

if (! is_string($token) || $token === '') {
    echo "Skipping live HTTP (no access token).\n";
    exit(0);
}

function httpGet(string $url, string $token): array
{
    $t0 = hrtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer '.$token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalMs = (curl_getinfo($ch, CURLINFO_TOTAL_TIME) ?: 0) * 1000;
    $err = curl_error($ch);
    curl_close($ch);
    $elapsed = (hrtime(true) - $t0) / 1e6;

    return [
        'code' => $code,
        'curl_ms' => $totalMs,
        'elapsed_ms' => $elapsed,
        'bytes' => is_string($body) ? strlen($body) : 0,
        'ok' => $code >= 200 && $code < 300,
        'err' => $err,
    ];
}

function httpParallel(array $urls, string $token): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer '.$token,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $t0 = hrtime(true);
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running && $status === CURLM_OK);
    $wall = (hrtime(true) - $t0) / 1e6;

    $results = [];
    foreach ($handles as $key => $ch) {
        $results[$key] = [
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'curl_ms' => (curl_getinfo($ch, CURLINFO_TOTAL_TIME) ?: 0) * 1000,
            'bytes' => strlen((string) curl_multi_getcontent($ch)),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return ['wall_ms' => $wall, 'requests' => $results];
}

$warmSkip = httpGet($apiBase.'/tenant/dashboard/executive?bundle=quick_actions', $token);
if (! $warmSkip['ok']) {
    echo sprintf("HTTP auth failed code=%d err=%s\n", $warmSkip['code'], $warmSkip['err']);
    exit(0);
}

$beforeHttp = httpParallel([
    'primary' => $apiBase.'/tenant/dashboard/executive?bundle=primary',
    'secondary' => $apiBase.'/tenant/dashboard/executive?bundle=secondary',
], $token);

$afterHttp = httpParallel([
    'snapshot' => $apiBase.'/tenant/dashboard/executive?bundle=snapshot',
    'celebrations' => $apiBase.'/tenant/dashboard/executive?bundle=celebrations',
    'quick_actions' => $apiBase.'/tenant/dashboard/executive?bundle=quick_actions',
    'stewardship' => $apiBase.'/tenant/dashboard/executive?bundle=stewardship',
    'mass' => $apiBase.'/tenant/dashboard/executive?bundle=mass',
    'pastoral' => $apiBase.'/tenant/dashboard/executive?bundle=pastoral',
    'ministries' => $apiBase.'/tenant/dashboard/executive?bundle=ministries',
], $token);

echo sprintf("Before parallel HTTP wall: %.1f ms\n", $beforeHttp['wall_ms']);
foreach ($beforeHttp['requests'] as $name => $row) {
    echo sprintf("  %s: code=%d curl=%.1fms bytes=%d\n", $name, $row['code'], $row['curl_ms'], $row['bytes']);
}
echo sprintf("After  parallel HTTP wall: %.1f ms\n", $afterHttp['wall_ms']);
foreach ($afterHttp['requests'] as $name => $row) {
    echo sprintf("  %s: code=%d curl=%.1fms bytes=%d\n", $name, $row['code'], $row['curl_ms'], $row['bytes']);
}
echo sprintf(
    "Live HTTP wall improvement: %.1f ms (%.0f%%)\n",
    $beforeHttp['wall_ms'] - $afterHttp['wall_ms'],
    $beforeHttp['wall_ms'] > 0 ? 100 * ($beforeHttp['wall_ms'] - $afterHttp['wall_ms']) / $beforeHttp['wall_ms'] : 0
);
