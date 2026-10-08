<?php

namespace Modules\Sacraments\Services\Certificates;

use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Marriage certificates require each party's parents' names and residence address on the register snapshot.
 */
final class MarriageCertificateRequirementsValidator
{
    /**
     * @throws SacramentBusinessRuleException
     */
    public function assertReady(Sacrament $sacrament): void
    {
        if (! SacramentTypeCode::isMatrimony($sacrament->sacramentType?->code)) {
            return;
        }

        $missing = $this->missingFields($sacrament);
        if ($missing === []) {
            return;
        }

        throw new SacramentBusinessRuleException(
            'marriage_certificate_incomplete',
            'The marriage certificate cannot be generated until required bride and groom details are recorded.',
            [
                'missing' => $missing,
                'message' => $this->humanMessage($missing),
            ]
        );
    }

    /**
     * @return list<string>
     */
    public function missingFields(Sacrament $sacrament): array
    {
        if (! SacramentTypeCode::isMatrimony($sacrament->sacramentType?->code)) {
            return [];
        }

        $missing = [];
        foreach ($this->partyFieldMap() as $party => $fields) {
            foreach ($fields as $key => $column) {
                if (! $this->filled($sacrament->getAttribute($column))) {
                    $missing[] = "{$party}.{$key}";
                }
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     */
    private function humanMessage(array $missing): string
    {
        $labels = [
            'bride.full_name' => "Bride's full name",
            'bride.father_name' => "Bride's father's name",
            'bride.mother_name' => "Bride's mother's name",
            'bride.address' => "Bride's address",
            'groom.full_name' => "Groom's full name",
            'groom.father_name' => "Groom's father's name",
            'groom.mother_name' => "Groom's mother's name",
            'groom.address' => "Groom's address",
        ];

        $parts = [];
        foreach ($missing as $key) {
            $parts[] = $labels[$key] ?? $key;
        }

        return 'Missing: '.implode('; ', $parts).'. Update the marriage register record (correct workflow) or link bride/groom to parish members with family addresses.';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function partyFieldMap(): array
    {
        return [
            'bride' => [
                'full_name' => 'marriage_bride_full_name',
                'father_name' => 'marriage_bride_father_name',
                'mother_name' => 'marriage_bride_mother_name',
                'address' => 'marriage_bride_address',
            ],
            'groom' => [
                'full_name' => 'marriage_groom_full_name',
                'father_name' => 'marriage_groom_father_name',
                'mother_name' => 'marriage_groom_mother_name',
                'address' => 'marriage_groom_address',
            ],
        ];
    }

    private function filled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
