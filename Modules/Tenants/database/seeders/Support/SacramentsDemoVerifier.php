<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Services\SacramentDashboardService;
use Modules\Sacraments\Support\SacramentStatus;

/**
 * Non-flaky checks for sacrament register demo coverage.
 */
final class SacramentsDemoVerifier
{
    public const MIN_DEMO_ROWS = 120;

    public const MIN_TYPES_WITH_ROWS = 5;

    /**
     * @return list<string>
     */
    public static function verify(int $tenantId): array
    {
        $errors = [];

        $demoCount = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();

        if ($demoCount < self::MIN_DEMO_ROWS) {
            $errors[] = sprintf(
                'Expected at least %d sacrament register demo rows (found %d). Run TenantSacramentsDemoSeeder after household seeding.',
                self::MIN_DEMO_ROWS,
                $demoCount
            );
        }

        $typeCount = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->distinct('sacrament_type_id')
            ->count('sacrament_type_id');

        if ($demoCount >= 10 && $typeCount < self::MIN_TYPES_WITH_ROWS) {
            $errors[] = 'Sacrament demo rows should span at least '.self::MIN_TYPES_WITH_ROWS.' sacrament types.';
        }

        $matrimonyIncomplete = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->whereHas('sacramentType', fn ($q) => $q->whereIn('code', ['marriage', 'MATRIMONY', 'matrimony']))
            ->get()
            ->first(fn (Sacrament $row) => ! SacramentsRegisterCertificateReadiness::isReady($row));

        if ($matrimonyIncomplete !== null) {
            $errors[] = sprintf(
                'Demo matrimony register row #%d is missing certificate-required bride/groom parents or address.',
                $matrimonyIncomplete->id
            );
        }

        $baptismIncomplete = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->whereHas('sacramentType', fn ($q) => $q->whereIn('code', ['baptism', 'BAPTISM']))
            ->get()
            ->first(fn (Sacrament $row) => ! SacramentsRegisterCertificateReadiness::isReady($row));

        if ($baptismIncomplete !== null) {
            $errors[] = sprintf(
                'Demo baptism register row #%d is missing recipient or parent names for certificates.',
                $baptismIncomplete->id
            );
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboardReconciliation(int $tenantId): array
    {
        $dashboard = app(SacramentDashboardService::class)->getSummary($tenantId, []);
        $repo = app(SacramentRepository::class);

        $dbActive = Sacrament::query()
            ->forTenant($tenantId)
            ->whereIn('status', [SacramentStatus::REGISTERED, SacramentStatus::CONDITIONAL])
            ->count();

        $listRegistered = $repo->getPaginated([
            'tenant_id' => $tenantId,
            'status' => SacramentStatus::REGISTERED,
            'per_page' => 1,
            'page' => 1,
        ])->total();
        $listConditional = $repo->getPaginated([
            'tenant_id' => $tenantId,
            'status' => SacramentStatus::CONDITIONAL,
            'per_page' => 1,
            'page' => 1,
        ])->total();
        $listActiveTotal = $listRegistered + $listConditional;

        return [
            'dashboard_total_all_time' => $dashboard['kpis']['total_all_time'] ?? null,
            'dashboard_total_period' => $dashboard['kpis']['total_period'] ?? null,
            'db_active_register_rows' => $dbActive,
            'list_active_total' => $listActiveTotal,
            'reconciled' => (int) ($dashboard['kpis']['total_all_time'] ?? -1) === $dbActive
                && $listActiveTotal === $dbActive,
        ];
    }
}
