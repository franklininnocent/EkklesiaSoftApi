<?php

declare(strict_types=1);

/**
 * Subprocess helper for PostgreSQL concurrency tests (separate DB connection).
 * Usage: php on-demand-materialize.php <tenant_id> <from Y-m-d> <to Y-m-d>
 */

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Modules\MassIntentions\Services\MassOccurrenceMaterializationService;

$tenantId = isset($argv[1]) ? (int) $argv[1] : 0;
$from = $argv[2] ?? '';
$to = $argv[3] ?? '';

if ($tenantId <= 0 || $from === '' || $to === '') {
    fwrite(STDERR, "Usage: php on-demand-materialize.php <tenant_id> <from> <to>\n");
    exit(2);
}

$base = realpath(__DIR__.'/../../../../');
if ($base === false) {
    fwrite(STDERR, "Could not resolve application base path.\n");
    exit(2);
}

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$frozen = getenv('MASS_TEST_NOW');
if (is_string($frozen) && $frozen !== '') {
    Carbon::setTestNow($frozen);
}

try {
    app(MassOccurrenceMaterializationService::class)->ensureForCelebrationList($tenantId, $from, $to);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

echo "ok\n";
exit(0);
