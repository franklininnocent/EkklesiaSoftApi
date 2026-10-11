<?php

namespace Modules\Tenants\Database\Seeders\Support;

/**
 * Idempotency marker for parish sacrament register demo fixtures.
 */
final class SacramentsDemoMarkers
{
    public const MARKER = 'sacraments_demo_v1';

    public const CERT_PREFIX = 'SDEMO-';

    public const ENV_TARGET = 'TENANT_DEMO_SACRAMENTS_TARGET';

    /** When true, purge demo + incomplete matrimony/baptism rows before seeding. */
    public const ENV_RESET = 'TENANT_DEMO_SACRAMENTS_RESET';

    /** Minimum register rows for pagination / dashboard QA (20 per page × 6+ pages). */
    public const DEFAULT_TARGET = 204;

    /** @var array<string, int> Share of {@see DEFAULT_TARGET} by canonical type code. */
    public const TYPE_QUOTAS = [
        'BAPTISM' => 40,
        'CONFIRMATION' => 28,
        'EUCHARIST' => 28,
        'MATRIMONY' => 36,
        'HOLY_ORDERS' => 8,
        'RECONCILIATION' => 22,
        'ANOINTING' => 30,
    ];
}
