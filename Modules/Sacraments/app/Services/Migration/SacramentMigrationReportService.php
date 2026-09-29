<?php

namespace Modules\Sacraments\Services\Migration;

use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentMigrationResolution;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Support\SacramentMigrationResolutionStatus;
use Modules\Sacraments\Support\SacramentParticipantSource;

/**
 * Migration progress report (ADR-11) — counts only, no PII.
 */
class SacramentMigrationReportService
{
    /**
     * @return array{
     *   total_resolutions:int,
     *   linked:int,
     *   unresolved:int,
     *   external:int,
     *   affiliation_incomplete:int,
     *   failed:int,
     *   sacraments_without_participants:int,
     *   unresolved_participants:int,
     *   participants_v1:bool
     * }
     */
    public function report(?int $tenantId = null): array
    {
        $resolutions = SacramentMigrationResolution::query();
        $participants = SacramentParticipant::query();
        $sacraments = Sacrament::query();

        if ($tenantId !== null) {
            $resolutions->where('tenant_id', $tenantId);
            $participants->where('tenant_id', $tenantId);
            $sacraments->where('tenant_id', $tenantId);
        }

        $total = (clone $resolutions)->count();
        $linked = (clone $resolutions)->where('resolution', SacramentMigrationResolutionStatus::MEMBER)->count();
        $unresolved = (clone $resolutions)->where('resolution', SacramentMigrationResolutionStatus::UNRESOLVED)->count();
        $external = (clone $resolutions)->where('resolution', SacramentMigrationResolutionStatus::EXTERNAL)->count();

        $affiliationIncomplete = (clone $participants)
            ->where('affiliation_type', 'other')
            ->where(function ($q) {
                $q->whereNull('affiliation_parish_name')
                    ->orWhere('affiliation_parish_name', '')
                    ->orWhereNull('affiliation_diocese_name')
                    ->orWhere('affiliation_diocese_name', '');
            })
            ->count();

        // Also count marriage denorm diocese gaps when affiliation other on sacrament columns.
        $marriageIncomplete = (clone $sacraments)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('marriage_bride_church_type', 'other')
                        ->where(function ($x) {
                            $x->whereNull('marriage_bride_diocese_name')
                                ->orWhere('marriage_bride_diocese_name', '');
                        });
                })->orWhere(function ($inner) {
                    $inner->where('marriage_groom_church_type', 'other')
                        ->where(function ($x) {
                            $x->whereNull('marriage_groom_diocese_name')
                                ->orWhere('marriage_groom_diocese_name', '');
                        });
                });
            })
            ->count();

        $withoutParticipants = (clone $sacraments)
            ->whereDoesntHave('participants')
            ->count();

        $unresolvedParticipants = (clone $participants)
            ->where('source', SacramentParticipantSource::UNRESOLVED)
            ->count();

        return [
            'total_resolutions' => $total,
            'linked' => $linked,
            'unresolved' => $unresolved,
            'external' => $external,
            'affiliation_incomplete' => $affiliationIncomplete + $marriageIncomplete,
            'failed' => 0,
            'sacraments_without_participants' => $withoutParticipants,
            'unresolved_participants' => $unresolvedParticipants,
            'participants_v1' => (bool) config('sacraments.participants_v1', false),
        ];
    }
}
