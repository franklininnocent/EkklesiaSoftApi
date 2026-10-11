<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Illuminate\Support\Facades\DB;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentCertificate;
use Modules\Sacraments\Models\SacramentIdempotencyKey;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Removes demo and/or certificate-incomplete sacrament register rows for a parish tenant.
 */
final class SacramentsDemoRegisterPurge
{
    /**
     * @return array{demo: int, incomplete: int, total: int}
     */
    public static function purgeTenant(int $tenantId, bool $removeIncomplete = true): array
    {
        $demoIds = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($tenantId) {
                $q->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
                    ->orWhere('certificate_number', 'like', SacramentsDemoMarkers::CERT_PREFIX.$tenantId.'-%');
            })
            ->pluck('id')
            ->all();

        $incompleteIds = [];
        if ($removeIncomplete) {
            Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->with('sacramentType')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$incompleteIds) {
                    foreach ($rows as $sacrament) {
                        $code = SacramentTypeCode::normalize($sacrament->sacramentType?->code);
                        if (! in_array($code, [SacramentTypeCode::MATRIMONY, SacramentTypeCode::BAPTISM], true)) {
                            continue;
                        }
                        if (! SacramentsRegisterCertificateReadiness::isReady($sacrament)) {
                            $incompleteIds[] = (int) $sacrament->id;
                        }
                    }
                });
        }

        $allIds = array_values(array_unique(array_merge($demoIds, $incompleteIds)));

        DB::transaction(function () use ($allIds, $tenantId) {
            if ($allIds !== []) {
                SacramentCertificate::query()->whereIn('sacrament_id', $allIds)->delete();
                Sacrament::query()->whereIn('id', $allIds)->delete();
            }
            SacramentIdempotencyKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', 'like', 'demo_'.SacramentsDemoMarkers::CERT_PREFIX.$tenantId.'-%')
                ->delete();
        });

        if ($allIds === []) {
            return ['demo' => 0, 'incomplete' => 0, 'total' => 0];
        }

        return [
            'demo' => count($demoIds),
            'incomplete' => count(array_diff($incompleteIds, $demoIds)),
            'total' => count($allIds),
        ];
    }
}
