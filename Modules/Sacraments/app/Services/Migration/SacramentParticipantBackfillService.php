<?php

namespace Modules\Sacraments\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentMigrationResolution;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Services\ParticipantSnapshotBuilder;
use Modules\Sacraments\Support\SacramentMigrationResolutionStatus;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentTypeCode;
use Throwable;

/**
 * Idempotent unresolved-first backfill (ADR-11).
 * Creates migration_resolution rows + sacrament_participants for legacy denorm names.
 */
class SacramentParticipantBackfillService
{
    public function __construct(
        protected SacramentNameMatchService $matcher,
        protected ParticipantSnapshotBuilder $snapshots
    ) {}

    /**
     * @return array{processed:int, linked:int, unresolved:int, skipped:int, failed:int, created_participants:int}
     */
    public function run(?int $tenantId = null, bool $dryRun = false, int $chunk = 200): array
    {
        $totals = [
            'processed' => 0,
            'linked' => 0,
            'unresolved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'created_participants' => 0,
        ];

        $query = Sacrament::query()
            ->with(['sacramentType', 'participants'])
            ->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $query->chunkById($chunk, function ($sacraments) use (&$totals, $dryRun) {
            foreach ($sacraments as $sacrament) {
                try {
                    $result = $this->backfillSacrament($sacrament, $dryRun);
                    $totals['processed']++;
                    $totals['linked'] += $result['linked'];
                    $totals['unresolved'] += $result['unresolved'];
                    $totals['skipped'] += $result['skipped'];
                    $totals['created_participants'] += $result['created_participants'];
                } catch (Throwable $e) {
                    $totals['failed']++;
                    Log::warning('Sacrament participant backfill failed', [
                        'sacrament_id' => $sacrament->id,
                        'tenant_id' => $sacrament->tenant_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $totals;
    }

    /**
     * @return array{linked:int, unresolved:int, skipped:int, created_participants:int}
     */
    public function backfillSacrament(Sacrament $sacrament, bool $dryRun = false): array
    {
        $slots = $this->legacySlots($sacrament);
        $linked = 0;
        $unresolved = 0;
        $skipped = 0;
        $created = 0;

        foreach ($slots as $slot) {
            $key = $this->migrationKey(
                (int) $sacrament->tenant_id,
                (int) $sacrament->id,
                $slot['role'],
                $slot['sort_order']
            );

            $existingResolution = SacramentMigrationResolution::query()
                ->where('migration_key', $key)
                ->first();

            // Already staff-resolved — do not overwrite.
            if ($existingResolution
                && in_array($existingResolution->resolution, [
                    SacramentMigrationResolutionStatus::MEMBER,
                    SacramentMigrationResolutionStatus::EXTERNAL,
                ], true)
                && $existingResolution->resolved_at
            ) {
                $skipped++;

                continue;
            }

            $match = $this->matcher->findExactUnique(
                (int) $sacrament->tenant_id,
                $slot['name'],
                $slot['dob']
            );

            $resolution = SacramentMigrationResolutionStatus::UNRESOLVED;
            $candidateId = null;
            if ($match['confidence'] === SacramentMigrationResolutionStatus::CONFIDENCE_EXACT && $match['member']) {
                $resolution = SacramentMigrationResolutionStatus::MEMBER;
                $candidateId = $match['member']->id;
                $linked++;
            } else {
                $unresolved++;
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use (
                $sacrament, $slot, $key, $resolution, $candidateId, $match, &$created
            ) {
                SacramentMigrationResolution::updateOrCreate(
                    ['migration_key' => $key],
                    [
                        'tenant_id' => $sacrament->tenant_id,
                        'legacy_sacrament_id' => $sacrament->id,
                        'participant_role' => $slot['role'],
                        'legacy_name' => $slot['name'],
                        'legacy_dob' => $slot['dob'],
                        'candidate_member_id' => $candidateId,
                        'confidence' => $match['confidence'],
                        'resolution' => $resolution,
                        'resolved_by' => $resolution === SacramentMigrationResolutionStatus::MEMBER
                            ? null
                            : null,
                        'resolved_at' => $resolution === SacramentMigrationResolutionStatus::MEMBER
                            ? now()
                            : null,
                    ]
                );

                $participant = SacramentParticipant::withTrashed()
                    ->where('sacrament_id', $sacrament->id)
                    ->where('role', $slot['role'])
                    ->where('sort_order', $slot['sort_order'])
                    ->first();

                if ($participant && $participant->trashed()) {
                    $participant->restore();
                }

                $attrs = $this->participantAttributes($slot, $resolution, $candidateId, $match['member'] ?? null);

                if ($participant) {
                    // Do not overwrite creatable sources already set by Phase 3+ UI.
                    if (in_array($participant->source, SacramentParticipantSource::creatable(), true)
                        && $participant->source !== SacramentParticipantSource::UNRESOLVED
                    ) {
                        return;
                    }
                    $participant->update($attrs);
                } else {
                    SacramentParticipant::create(array_merge($attrs, [
                        'tenant_id' => $sacrament->tenant_id,
                        'sacrament_id' => $sacrament->id,
                        'role' => $slot['role'],
                        'sort_order' => $slot['sort_order'],
                    ]));
                    $created++;
                }
            });
        }

        return [
            'linked' => $linked,
            'unresolved' => $unresolved,
            'skipped' => $skipped,
            'created_participants' => $created,
        ];
    }

    public function migrationKey(int $tenantId, int $sacramentId, string $role, int $sortOrder): string
    {
        return sprintf('%d:%d:%s:%d', $tenantId, $sacramentId, $role, $sortOrder);
    }

    /**
     * @return list<array{role:string, name:string, dob:?string, sort_order:int, title?:?string}>
     */
    private function legacySlots(Sacrament $sacrament): array
    {
        $typeCode = SacramentTypeCode::normalize($sacrament->sacramentType?->code)
            ?? strtoupper((string) ($sacrament->sacramentType?->code ?? ''));

        $slots = [];

        if ($typeCode === SacramentTypeCode::MATRIMONY) {
            $this->pushSlot($slots, SacramentParticipantRole::BRIDE, $sacrament->marriage_bride_full_name, null, 0);
            $this->pushSlot($slots, SacramentParticipantRole::GROOM, $sacrament->marriage_groom_full_name, null, 0);
        } else {
            $dob = $sacrament->recipient_birth_date
                ? (string) optional($sacrament->recipient_birth_date)->format('Y-m-d')
                : null;
            $this->pushSlot($slots, SacramentParticipantRole::RECIPIENT, $sacrament->recipient_name, $dob, 0);
            $this->pushSlot($slots, SacramentParticipantRole::FATHER, $sacrament->father_name, null, 0);
            $this->pushSlot($slots, SacramentParticipantRole::MOTHER, $sacrament->mother_name, null, 0);
            $this->pushSlot($slots, SacramentParticipantRole::GODFATHER, $sacrament->godparent1_name, null, 0);
            $this->pushSlot($slots, SacramentParticipantRole::GODMOTHER, $sacrament->godparent2_name, null, 0);
        }

        $this->pushSlot(
            $slots,
            SacramentParticipantRole::MINISTER,
            $sacrament->minister_name,
            null,
            0,
            $sacrament->minister_title
        );

        return $slots;
    }

    /**
     * @param  list<array{role:string, name:string, dob:?string, sort_order:int, title?:?string}>  $slots
     */
    private function pushSlot(
        array &$slots,
        string $role,
        ?string $name,
        ?string $dob,
        int $sortOrder,
        ?string $title = null
    ): void {
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }
        $slots[] = [
            'role' => $role,
            'name' => $name,
            'dob' => $dob,
            'sort_order' => $sortOrder,
            'title' => $title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantAttributes(
        array $slot,
        string $resolution,
        ?string $candidateId,
        mixed $member
    ): array {
        if ($resolution === SacramentMigrationResolutionStatus::MEMBER && $candidateId) {
            $row = [
                'source' => SacramentParticipantSource::MEMBER,
                'family_member_id' => $candidateId,
                'church_leadership_id' => null,
                'external_full_name' => null,
                'external_date_of_birth' => null,
                'external_title' => null,
                '_resolved_member' => $member,
            ];
            $row['snapshot_json'] = $this->snapshots->build($row, SacramentParticipantSource::MEMBER);
            unset($row['_resolved_member']);

            return $row;
        }

        $row = [
            'source' => SacramentParticipantSource::UNRESOLVED,
            'family_member_id' => null,
            'church_leadership_id' => null,
            'external_full_name' => $slot['name'],
            'external_date_of_birth' => $slot['dob'],
            'external_title' => $slot['title'] ?? null,
        ];
        $row['snapshot_json'] = $this->snapshots->build($row, SacramentParticipantSource::UNRESOLVED);

        return $row;
    }
}
