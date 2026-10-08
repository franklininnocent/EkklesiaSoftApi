<?php

/**
 * Local profiler for /tenant/dashboard/executive. Not used in production.
 * Run: php scripts/profile-executive-dashboard.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantExecutiveDashboardComposer;
use Modules\Tenants\Support\TenantCacheVersion;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()
    ->whereNotNull('tenant_id')
    ->where('active', 1)
    ->orderBy('id')
    ->first();

if (! $user) {
    fwrite(STDERR, "No tenant user found.\n");
    exit(1);
}

$tenantId = (int) $user->tenant_id;
echo "user_id={$user->id} tenant_id={$tenantId} email={$user->email}\n";

auth()->setUser($user);

$composer = app(TenantExecutiveDashboardComposer::class);
$ref = new ReflectionClass($composer);

$queries = [];
DB::listen(function ($query) use (&$queries) {
    $queries[] = [
        'sql' => $query->sql,
        'time_ms' => $query->time,
        'bindings_n' => count($query->bindings),
    ];
});

function timeSection(callable $fn): array
{
    $t0 = hrtime(true);
    $value = $fn();
    $ms = (hrtime(true) - $t0) / 1e6;

    return [$value, $ms];
}

function payloadBytes(mixed $value): int
{
    return strlen(json_encode($value) ?: '');
}

echo "\n=== Uncached section timings (same process, sequential) ===\n";

$buildUncached = $ref->getMethod('buildUncached');
$buildUncached->setAccessible(true);

$sections = [
    'primary' => 'primary',
    'secondary' => 'secondary',
];

foreach ($sections as $label => $bundle) {
    TenantCacheVersion::bump($tenantId);
    $queries = [];
    [$payload, $ms] = timeSection(fn () => $buildUncached->invoke($composer, $user, $tenantId, $bundle));
    $bytes = payloadBytes($payload);
    $slow = array_values(array_filter($queries, fn ($q) => $q['time_ms'] >= 20));
    usort($slow, fn ($a, $b) => $b['time_ms'] <=> $a['time_ms']);
    echo sprintf(
        "%s: %.1fms queries=%d bytes=%d keys=%s\n",
        $label,
        $ms,
        count($queries),
        $bytes,
        implode(',', array_keys($payload))
    );
    echo "  slow (>=20ms):\n";
    foreach (array_slice($slow, 0, 15) as $q) {
        echo sprintf("    %.1fms %s\n", $q['time_ms'], substr(preg_replace('/\s+/', ' ', $q['sql']), 0, 180));
    }
    if ($slow === []) {
        echo "    (none)\n";
    }
}

$privateMethods = [
    'buildSnapshot',
    'buildCelebrations',
    'buildQuickActions',
    'buildAttention',
    'buildStewardship',
    'buildMassIntentions',
    'buildWorship',
    'buildPastoral',
    'buildMinistries',
];

echo "\n=== Isolated builders (new composer each, cold in-request caches) ===\n";
$tenant = Tenant::query()->find($tenantId);

foreach ($privateMethods as $name) {
    $isolated = app(TenantExecutiveDashboardComposer::class);
    $refI = new ReflectionClass($isolated);
    $m = $refI->getMethod($name);
    $m->setAccessible(true);
    $queries = [];
    [$payload, $ms] = timeSection(function () use ($m, $isolated, $user, $tenantId, $tenant, $name) {
        return match ($name) {
            'buildQuickActions' => $m->invoke($isolated, $user, $tenant),
            'buildCelebrations', 'buildPastoral' => $m->invoke($isolated, $user, $tenantId),
            default => $m->invoke($isolated, $user, $tenantId, $tenant),
        };
    });
    echo sprintf("%s: %.1fms queries=%d bytes=%d\n", $name, $ms, count($queries), payloadBytes($payload));
}

echo "\n=== Duplicate SQL fingerprints in a full 'all' build ===\n";
TenantCacheVersion::bump($tenantId);
$queries = [];
$composer3 = app(TenantExecutiveDashboardComposer::class);
$ref3 = new ReflectionClass($composer3);
$buildUncached3 = $ref3->getMethod('buildUncached');
$buildUncached3->setAccessible(true);
[, $allMs] = timeSection(fn () => $buildUncached3->invoke($composer3, $user, $tenantId, 'all'));
$fingerprints = [];
foreach ($queries as $q) {
    $fp = preg_replace('/\s+/', ' ', $q['sql']);
    $fingerprints[$fp] = ($fingerprints[$fp] ?? 0) + 1;
}
$dupes = array_filter($fingerprints, fn ($n) => $n > 1);
arsort($dupes);
echo sprintf("all: %.1fms queries=%d duplicate_sql_shapes=%d\n", $allMs, count($queries), count($dupes));
foreach (array_slice($dupes, 0, 20, true) as $sql => $n) {
    echo sprintf("  x%d %s\n", $n, substr($sql, 0, 160));
}

echo "\n=== Cached primary+secondary (TTL remember) ===\n";
$queries = [];
$t0 = hrtime(true);
$p = $composer->build($user, $tenantId, 'primary');
$s = $composer->build($user, $tenantId, 'secondary');
$cachedMs = (hrtime(true) - $t0) / 1e6;
echo sprintf("cached both: %.1fms queries=%d primary_bytes=%d secondary_bytes=%d\n", $cachedMs, count($queries), payloadBytes($p), payloadBytes($s));
