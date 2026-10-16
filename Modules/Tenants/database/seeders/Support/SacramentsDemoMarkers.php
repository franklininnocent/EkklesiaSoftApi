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

    /** Compact demo register row target (representative coverage across types). */
    public const DEFAULT_TARGET = 48;

    /** @var array<string, int> Share of {@see DEFAULT_TARGET} by canonical type code. */
    public const TYPE_QUOTAS = [
        'BAPTISM' => 10,
        'CONFIRMATION' => 6,
        'EUCHARIST' => 6,
        'MATRIMONY' => 8,
        'HOLY_ORDERS' => 2,
        'RECONCILIATION' => 8,
        'ANOINTING' => 8,
    ];
}
