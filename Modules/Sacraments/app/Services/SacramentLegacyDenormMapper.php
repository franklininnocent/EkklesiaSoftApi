<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Support\SacramentParticipantRole;

/**
 * Dual-write participant rows → legacy flat sacrament columns for list/search/export.
 */
class SacramentLegacyDenormMapper
{
    /**
     * @param  list<array<string, mixed>>  $participants  rows with snapshot_json already built
     * @return array<string, mixed>
     */
    public function toSacramentAttributes(array $participants): array
    {
        $attrs = [];
        $witnessNames = [];

        foreach ($participants as $p) {
            $role = $p['role'] ?? null;
            $name = $this->displayName($p);
            $snapshot = $p['snapshot_json'] ?? [];

            switch ($role) {
                case SacramentParticipantRole::RECIPIENT:
                case SacramentParticipantRole::CANDIDATE:
                    $attrs['recipient_name'] = $name;
                    if (! empty($snapshot['date_of_birth'])) {
                        $attrs['recipient_birth_date'] = $snapshot['date_of_birth'];
                    } elseif (! empty($p['external_date_of_birth'])) {
                        $attrs['recipient_birth_date'] = $p['external_date_of_birth'];
                    }
                    if (! empty($snapshot['gender'])) {
                        $attrs['recipient_gender'] = $snapshot['gender'];
                    } elseif (! empty($p['external_gender'])) {
                        $attrs['recipient_gender'] = $p['external_gender'];
                    }
                    if (! empty($snapshot['place_of_birth'])) {
                        $attrs['recipient_birth_place'] = $snapshot['place_of_birth'];
                    }
                    break;

                case SacramentParticipantRole::SPONSOR:
                    if ($name) {
                        if (empty($attrs['godparent1_name'])) {
                            $attrs['godparent1_name'] = $name;
                        } elseif (empty($attrs['godparent2_name'])) {
                            $attrs['godparent2_name'] = $name;
                        }
                    }
                    break;

                case SacramentParticipantRole::CO_CONSECRATOR:
                    if ($name) {
                        $witnessNames[] = 'Co-consecrator: '.$name;
                    }
                    break;

                case SacramentParticipantRole::FATHER:
                    $attrs['father_name'] = $name;
                    break;

                case SacramentParticipantRole::MOTHER:
                    $attrs['mother_name'] = $name;
                    break;

                case SacramentParticipantRole::GODFATHER:
                    $attrs['godparent1_name'] = $name;
                    break;

                case SacramentParticipantRole::GODMOTHER:
                    $attrs['godparent2_name'] = $name;
                    break;

                case SacramentParticipantRole::MINISTER:
                    $attrs['minister_name'] = $name;
                    $attrs['minister_title'] = $p['external_title']
                        ?? ($snapshot['title'] ?? null);
                    break;

                case SacramentParticipantRole::BRIDE:
                    $attrs['marriage_bride_full_name'] = $name;
                    $attrs['marriage_bride_church_type'] = $p['affiliation_type'] ?? null;
                    $attrs['marriage_bride_church_name'] = $p['affiliation_parish_name'] ?? null;
                    $attrs['marriage_bride_church_address'] = $p['affiliation_parish_address'] ?? null;
                    $attrs['marriage_bride_diocese_name'] = $p['affiliation_diocese_name'] ?? null;
                    $attrs['marriage_bride_diocese_region'] = $p['affiliation_diocese_region'] ?? null;
                    $attrs['marriage_bride_diocese_country'] = $p['affiliation_diocese_country'] ?? null;
                    // recipient_name convenience for list search
                    $attrs['recipient_name'] = $attrs['recipient_name'] ?? $name;
                    break;

                case SacramentParticipantRole::GROOM:
                    $attrs['marriage_groom_full_name'] = $name;
                    $attrs['marriage_groom_church_type'] = $p['affiliation_type'] ?? null;
                    $attrs['marriage_groom_church_name'] = $p['affiliation_parish_name'] ?? null;
                    $attrs['marriage_groom_church_address'] = $p['affiliation_parish_address'] ?? null;
                    $attrs['marriage_groom_diocese_name'] = $p['affiliation_diocese_name'] ?? null;
                    $attrs['marriage_groom_diocese_region'] = $p['affiliation_diocese_region'] ?? null;
                    $attrs['marriage_groom_diocese_country'] = $p['affiliation_diocese_country'] ?? null;
                    if (empty($attrs['recipient_name'])) {
                        $attrs['recipient_name'] = $name;
                    } else {
                        $attrs['recipient_name'] = $attrs['recipient_name'].' & '.$name;
                    }
                    break;

                case SacramentParticipantRole::WITNESS:
                    if ($name) {
                        $witnessNames[] = $name;
                    }
                    break;
            }
        }

        if ($witnessNames !== []) {
            $attrs['witnesses'] = implode(', ', $witnessNames);
        }

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $participant
     */
    private function displayName(array $participant): ?string
    {
        $snapshot = $participant['snapshot_json'] ?? [];
        if (! empty($snapshot['full_name'])) {
            return (string) $snapshot['full_name'];
        }

        return isset($participant['external_full_name'])
            ? (string) $participant['external_full_name']
            : null;
    }
}
