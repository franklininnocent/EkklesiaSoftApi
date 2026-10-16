<?php

namespace Modules\Sacraments\Services\Migration;

use Illuminate\Support\Facades\DB;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\SacramentMigrationResolution;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Services\ParticipantSnapshotBuilder;
use Modules\Sacraments\Services\SacramentAuditService;
use Modules\Sacraments\Services\SacramentLegacyDenormMapper;
use Modules\Sacraments\Support\SacramentMigrationResolutionStatus;
use Modules\Sacraments\Support\SacramentParticipantSource;

/**
 * Staff resolve migration rows → member | external (ADR-11).
 */
class SacramentMigrationResolveService
{
    public function __construct(
        protected ParticipantSnapshotBuilder $snapshots,
        protected SacramentLegacyDenormMapper $denormMapper,
        protected SacramentAuditService $audit
    ) {}

    /**
     * @param  array{resolution:string, family_member_id?:string, external_full_name?:string, external_date_of_birth?:string}  $data
     */
    public function resolve(int $resolutionId, int $tenantId, array $data, ?int $actorId = null): SacramentMigrationResolution
    {
        $row = SacramentMigrationResolution::query()->find($resolutionId);
        if (! $row || (int) $row->tenant_id !== $tenantId) {
            throw new SacramentBusinessRuleException(
                'migration_resolution_not_found',
                'Migration resolution not found.',
                [],
                404
            );
        }

        $resolution = (string) $data['resolution'];
        if (! in_array($resolution, [
            SacramentMigrationResolutionStatus::MEMBER,
            SacramentMigrationResolutionStatus::EXTERNAL,
        ], true)) {
            throw new SacramentBusinessRuleException(
                'invalid_migration_resolution',
                'Resolution must be member or external.'
            );
        }

        $before = $row->only([
            'id', 'resolution', 'candidate_member_id', 'legacy_name', 'participant_role',
        ]);

        $participantAttrs = [];
        if ($resolution === SacramentMigrationResolutionStatus::MEMBER) {
            $memberId = $data['family_member_id'] ?? $row->candidate_member_id;
            if (! $memberId) {
                throw new SacramentBusinessRuleException(
                    'member_required',
                    'family_member_id is required when resolving as member.'
                );
            }

            $member = FamilyMember::query()
                ->where('id', $memberId)
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if (! $member) {
                throw new SacramentBusinessRuleException(
                    'cross_tenant_member',
                    'Family member not found in this parish.'
                );
            }

            $participantAttrs = [
                'source' => SacramentParticipantSource::MEMBER,
                'family_member_id' => $member->id,
                'church_leadership_id' => null,
                'external_full_name' => null,
                'external_date_of_birth' => null,
                '_resolved_member' => $member,
            ];
            $participantAttrs['snapshot_json'] = $this->snapshots->build(
                $participantAttrs,
                SacramentParticipantSource::MEMBER
            );
            unset($participantAttrs['_resolved_member']);

            $row->candidate_member_id = $member->id;
        } else {
            $name = trim((string) ($data['external_full_name'] ?? $row->legacy_name ?? ''));
            if ($name === '') {
                throw new SacramentBusinessRuleException(
                    'external_name_required',
                    'external_full_name is required when resolving as external.'
                );
            }
            $dob = $data['external_date_of_birth'] ?? optional($row->legacy_dob)?->format('Y-m-d');
            $participantAttrs = [
                'source' => SacramentParticipantSource::EXTERNAL,
                'family_member_id' => null,
                'church_leadership_id' => null,
                'external_full_name' => $name,
                'external_date_of_birth' => $dob,
            ];
            $participantAttrs['snapshot_json'] = $this->snapshots->build(
                $participantAttrs,
                SacramentParticipantSource::EXTERNAL
            );
        }

        return DB::transaction(function () use ($row, $resolution, $participantAttrs, $tenantId, $actorId, $before) {
            $participant = SacramentParticipant::query()
                ->where('tenant_id', $tenantId)
                ->where('sacrament_id', $row->legacy_sacrament_id)
                ->where('role', $row->participant_role)
                ->orderBy('sort_order')
                ->first();

            if ($participant) {
                $participant->update($participantAttrs);
            } else {
                SacramentParticipant::create(array_merge($participantAttrs, [
                    'tenant_id' => $tenantId,
                    'sacrament_id' => $row->legacy_sacrament_id,
                    'role' => $row->participant_role,
                    'sort_order' => 0,
                ]));
            }

            $participants = SacramentParticipant::query()
                ->where('sacrament_id', $row->legacy_sacrament_id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($p) => array_merge($p->toArray(), [
                    'snapshot_json' => $p->snapshot_json,
                ]))
                ->all();

            $denorm = $this->denormMapper->toSacramentAttributes($participants);
            if ($denorm !== []) {
                $row->legacySacrament()->update($denorm);
            }

            $row->resolution = $resolution;
            $row->resolved_by = $actorId;
            $row->resolved_at = now();
            $row->save();

            $this->audit->log(
                $tenantId,
                'migration_resolve',
                (string) $row->id,
                $before,
                $row->only(['id', 'resolution', 'candidate_member_id', 'legacy_name', 'participant_role']),
                ['sacrament_id' => $row->legacy_sacrament_id],
                'sacrament_migration_resolution'
            );

            return $row->fresh(['candidateMember', 'legacySacrament']);
        });
    }
}
