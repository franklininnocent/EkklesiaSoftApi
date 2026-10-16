<?php

namespace Modules\Sacraments\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentEventSubtype;
use Modules\Sacraments\Support\SacramentOrdinationType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Definition-driven duplicate warnings (Phase 10). Not a hard DB unique.
 */
class SacramentDuplicateDetector
{
    public function __construct(
        protected SacramentDefinitionRegistry $definitions
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     * @return array<string, mixed>|null
     */
    public function detect(int $tenantId, SacramentType $type, array $data, array $normalized): ?array
    {
        $definition = $this->definitions->forTypeCode($type->code) ?? [];
        $policy = (string) ($definition['repeatability_policy'] ?? 'warn_same_person_type_date');
        $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);

        return match ($policy) {
            'none' => null,
            'warn_same_person_subtype' => $this->warnSamePersonSubtype($tenantId, $type, $data, $normalized, $code),
            'warn_same_candidate_ordination' => $this->warnSameCandidateOrdination($tenantId, $type, $data, $normalized),
            'warn_marriage_parties_date' => $this->warnMarriage($tenantId, $type, $data, $normalized),
            default => $this->warnSamePersonTypeDate($tenantId, $type, $data, $normalized, $code, $definition),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function warnSamePersonTypeDate(
        int $tenantId,
        SacramentType $type,
        array $data,
        array $normalized,
        string $code,
        array $definition
    ): ?array {
        $repeatable = (bool) ($definition['repeatable'] ?? $type->repeatable ?? false);
        $date = $data['date_administered'] ?? null;

        // Non-repeatable: warn on same person + type (any date).
        // Repeatable: warn only when same person + type + same date.
        if ($repeatable && ! $date) {
            return null;
        }

        $person = $this->primaryPersonName($normalized, $code);
        if ($person === null) {
            return null;
        }

        $query = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_type_id', $type->id)
            ->whereNull('deleted_at')
            ->where('recipient_name', $person);

        if ($repeatable) {
            $query->whereDate('date_administered', $date);
        }

        return $this->firstWarning($query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     * @return array<string, mixed>|null
     */
    private function warnSamePersonSubtype(
        int $tenantId,
        SacramentType $type,
        array $data,
        array $normalized,
        string $code
    ): ?array {
        $subtype = SacramentEventSubtype::normalize($data['event_subtype'] ?? null)
            ?? SacramentEventSubtype::FIRST_COMMUNION;
        $person = $this->primaryPersonName($normalized, $code);
        if ($person === null) {
            return null;
        }

        $query = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_type_id', $type->id)
            ->where('event_subtype', $subtype)
            ->whereNull('deleted_at')
            ->where('recipient_name', $person);

        return $this->firstWarning($query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     * @return array<string, mixed>|null
     */
    private function warnSameCandidateOrdination(
        int $tenantId,
        SacramentType $type,
        array $data,
        array $normalized
    ): ?array {
        $ordination = SacramentOrdinationType::normalize(
            data_get($data, 'typed_attributes.ordination_type')
        );
        $person = $this->nameForRole($normalized, SacramentParticipantRole::CANDIDATE);
        if ($person === null || $ordination === null) {
            return null;
        }

        $query = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_type_id', $type->id)
            ->whereNull('deleted_at')
            ->where('recipient_name', $person)
            ->where('typed_attributes->ordination_type', $ordination);

        return $this->firstWarning($query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     * @return array<string, mixed>|null
     */
    private function warnMarriage(int $tenantId, SacramentType $type, array $data, array $normalized): ?array
    {
        $date = $data['date_administered'] ?? null;
        if (! $date) {
            return null;
        }

        $bride = $this->nameForRole($normalized, SacramentParticipantRole::BRIDE);
        $groom = $this->nameForRole($normalized, SacramentParticipantRole::GROOM);

        $query = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_type_id', $type->id)
            ->whereDate('date_administered', $date)
            ->whereNull('deleted_at');

        if ($bride) {
            $query->where('marriage_bride_full_name', $bride);
        }
        if ($groom) {
            $query->where('marriage_groom_full_name', $groom);
        }

        return $this->firstWarning($query);
    }

    /**
     * @param  list<array<string, mixed>>  $normalized
     */
    private function primaryPersonName(array $normalized, string $code): ?string
    {
        if ($code === SacramentTypeCode::HOLY_ORDERS) {
            return $this->nameForRole($normalized, SacramentParticipantRole::CANDIDATE);
        }
        if ($code === SacramentTypeCode::MATRIMONY) {
            return null;
        }

        return $this->nameForRole($normalized, SacramentParticipantRole::RECIPIENT);
    }

    /**
     * @param  list<array<string, mixed>>  $normalized
     */
    private function nameForRole(array $normalized, string $role): ?string
    {
        foreach ($normalized as $p) {
            if (($p['role'] ?? null) !== $role) {
                continue;
            }

            $name = $p['snapshot_json']['full_name'] ?? $p['external_full_name'] ?? null;

            return $name ? (string) $name : null;
        }

        return null;
    }

    /**
     * @param  Builder  $query
     * @return array<string, mixed>|null
     */
    private function firstWarning($query): ?array
    {
        $existing = $query->first(['id', 'recipient_name', 'date_administered', 'status', 'event_subtype']);
        if (! $existing) {
            return null;
        }

        return [
            'existing_sacrament_id' => $existing->id,
            'recipient_name' => $existing->recipient_name,
            'date_administered' => optional($existing->date_administered)?->format('Y-m-d'),
            'status' => $existing->status,
            'event_subtype' => $existing->event_subtype,
        ];
    }
}
