<?php

namespace Modules\Sacraments\Services\Certificates;

use Illuminate\Support\Facades\DB;
use Modules\Family\app\Services\FamilyMemberParentNameResolver;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Fills empty marriage register denormalized fields and participant snapshots from linked parish members.
 */
final class MarriageRegisterCertificateEnricher
{
    public function __construct(
        private readonly FamilyMemberParentNameResolver $parentNameResolver,
    ) {}

    public function enrich(Sacrament $sacrament, int $tenantId): bool
    {
        if (! SacramentTypeCode::isMatrimony($sacrament->sacramentType?->code)) {
            return false;
        }

        $sacrament->loadMissing(['sacramentType', 'participants']);

        return DB::transaction(function () use ($sacrament, $tenantId) {
            $changed = false;

            foreach ([SacramentParticipantRole::BRIDE, SacramentParticipantRole::GROOM] as $role) {
                $participant = $sacrament->participants->first(
                    fn (SacramentParticipant $p) => strtolower((string) $p->role) === strtolower($role)
                );
                if (! $participant) {
                    continue;
                }

                $prefix = $role === SacramentParticipantRole::BRIDE ? 'marriage_bride_' : 'marriage_groom_';
                $party = $this->resolvePartyDetails($participant, $tenantId, $prefix);

                if ($party === null) {
                    continue;
                }

                $updates = [];
                foreach ([
                    'full_name' => $prefix.'full_name',
                    'father_name' => $prefix.'father_name',
                    'mother_name' => $prefix.'mother_name',
                    'address' => $prefix.'address',
                ] as $key => $column) {
                    if (! $this->filled($sacrament->getAttribute($column)) && $this->filled($party[$key] ?? null)) {
                        $updates[$column] = $party[$key];
                    }
                }

                if ($updates !== []) {
                    $sacrament->fill($updates);
                    $changed = true;
                }

                $snapshot = is_array($participant->snapshot_json) ? $participant->snapshot_json : [];
                $snapshotUpdates = [];
                foreach (['full_name', 'father_name', 'mother_name', 'address'] as $snapKey) {
                    if (! $this->filled($snapshot[$snapKey] ?? null) && $this->filled($party[$snapKey] ?? null)) {
                        $snapshotUpdates[$snapKey] = $party[$snapKey];
                    }
                }
                if ($snapshotUpdates !== []) {
                    $participant->snapshot_json = array_merge($snapshot, $snapshotUpdates);
                    $participant->save();
                    $changed = true;
                }
            }

            if ($changed) {
                $sacrament->save();
                $sacrament->load('participants');
            }

            return $changed;
        });
    }

    /**
     * @return array{full_name: ?string, father_name: ?string, mother_name: ?string, address: ?string}|null
     */
    private function resolvePartyDetails(SacramentParticipant $participant, int $tenantId, string $prefix): ?array
    {
        $snapshot = is_array($participant->snapshot_json) ? $participant->snapshot_json : [];

        if ($participant->source === SacramentParticipantSource::MEMBER && $participant->family_member_id) {
            $member = FamilyMember::query()
                ->where('id', $participant->family_member_id)
                ->whereNull('deleted_at')
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->with(['family', 'person'])
                ->first();

            if (! $member) {
                return null;
            }

            $familyMembers = FamilyMember::query()
                ->where('family_id', $member->family_id)
                ->whereNull('deleted_at')
                ->get(['id', 'family_id', 'first_name', 'middle_name', 'last_name', 'relationship_to_head', 'gender', 'person_id']);

            $parents = $this->parentNameResolver->resolveForMember($member, $familyMembers);

            $address = $this->firstFilled(
                $snapshot['address'] ?? null,
                $this->formatFamilyAddress($member->family),
                $this->formatPersonAddress($member->person),
            );

            $fullName = $this->firstFilled(
                $snapshot['full_name'] ?? null,
                trim(implode(' ', array_filter([
                    $member->first_name,
                    $member->middle_name,
                    $member->last_name,
                ])))
            );

            return [
                'full_name' => $fullName,
                'father_name' => $this->firstFilled(
                    $snapshot['father_name'] ?? null,
                    $parents['father_name'] ?? null,
                    $member->person?->father_name,
                ),
                'mother_name' => $this->firstFilled(
                    $snapshot['mother_name'] ?? null,
                    $parents['mother_name'] ?? null,
                    $member->person?->mother_name,
                ),
                'address' => $address,
            ];
        }

        if ($participant->source === SacramentParticipantSource::EXTERNAL) {
            return [
                'full_name' => $this->firstFilled($snapshot['full_name'] ?? null, $participant->external_full_name),
                'father_name' => $this->firstFilled($snapshot['father_name'] ?? null),
                'mother_name' => $this->firstFilled($snapshot['mother_name'] ?? null),
                'address' => $this->firstFilled($snapshot['address'] ?? null, $participant->external_address),
            ];
        }

        return null;
    }

    private function formatFamilyAddress(?Family $family): ?string
    {
        if (! $family) {
            return null;
        }

        $parts = array_filter([
            $family->address_line_1,
            $family->address_line_2,
            $family->city,
            $family->postal_code,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function formatPersonAddress(?Person $person): ?string
    {
        if (! $person) {
            return null;
        }

        $parts = array_filter([
            $person->address_line_1,
            $person->address_line_2,
            $person->city,
            $person->postal_code,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($this->filled($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function filled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
