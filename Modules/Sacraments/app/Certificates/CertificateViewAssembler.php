<?php

namespace Modules\Sacraments\Certificates;

final class CertificateViewAssembler
{
    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public function assemble(array $projection): array
    {
        $sacrament = is_array($projection['sacrament'] ?? null) ? $projection['sacrament'] : [];
        $church = is_array($projection['church'] ?? null) ? $projection['church'] : [];
        $participants = is_array($projection['participants'] ?? null) ? $projection['participants'] : [];
        $render = is_array($projection['render'] ?? null) ? $projection['render'] : [];

        $denominationType = (string) ($church['denomination_type'] ?? DenominationMapper::GENERIC);
        $engineType = DenominationMapper::engineSacramentType(
            (string) ($sacrament['type_code'] ?? ''),
            $denominationType
        );
        $terms = LiturgicalTerminologyCatalog::for($denominationType, $engineType);
        $themeId = (string) ($render['theme_id'] ?? DenominationMapper::themeId($denominationType));

        $byRole = [];
        foreach ($participants as $row) {
            $role = strtolower((string) ($row['role'] ?? ''));
            $name = trim((string) ($row['display_name'] ?? ''));
            if ($role === '' || $name === '') {
                continue;
            }
            $byRole[$role][] = $name;
        }

        $first = static function (array $byRole, array $roles, ?string $fallback = null): ?string {
            foreach ($roles as $role) {
                if (! empty($byRole[$role][0])) {
                    return $byRole[$role][0];
                }
            }

            return $fallback ?: null;
        };

        $view = [
            'sacramentType' => $engineType,
            'certificateTitle' => $terms['sacramentTitle'],
            'subtitle' => $terms['subtitle'],
            'church' => [
                'name' => $church['name'] ?? 'Parish Church',
                'diocese' => $church['diocese'] ?? null,
                'address' => $church['address'] ?? null,
                'logoUrl' => null,
                'sealUrl' => $church['seal_url'] ?? null,
                'denominationCode' => $church['denomination_code'] ?? 'GENERIC',
                'denominationType' => $denominationType,
                'parishCode' => $church['parish_code'] ?? null,
            ],
            'dateOfEvent' => $sacrament['date_administered'] ?? null,
            'placeOfEvent' => $sacrament['place_administered'] ?? null,
            'registry' => [
                'bookNumber' => $sacrament['book_number'] ?? null,
                'pageNumber' => $sacrament['page_number'] ?? null,
                'registryEntry' => $sacrament['registry_entry'] ?? null,
                'certificateNumber' => $sacrament['certificate_number'] ?? null,
            ],
            'locale' => $projection['locale'] ?? 'en',
            'paper' => $render['paper'] ?? 'A4',
            'themeId' => $themeId,
            'emblem' => CertificateThemeCatalog::CANONICAL_EMBLEM,
            'terminology' => $terms,
            'verificationUrl' => $projection['verification']['url'] ?? null,
            'verificationQrDataUri' => $projection['verification']['qr_data_uri'] ?? null,
            'issuedAt' => $projection['issued_at'] ?? null,
        ];

        if ($engineType === 'BAPTISM') {
            $sponsors = array_values(array_filter(array_merge(
                $byRole['godfather'] ?? [],
                $byRole['godmother'] ?? [],
                $byRole['godparent'] ?? [],
                $byRole['sponsor'] ?? [],
            )));
            if ($sponsors === []) {
                $sponsors = array_values(array_filter([
                    $sacrament['godparent1_name'] ?? null,
                    $sacrament['godparent2_name'] ?? null,
                ]));
            }
            $view['recipientName'] = $first($byRole, ['recipient'], $sacrament['recipient_name'] ?? null);
            $view['fatherName'] = $first($byRole, ['father'], $sacrament['father_name'] ?? null);
            $view['motherName'] = $first($byRole, ['mother'], $sacrament['mother_name'] ?? null);
            $view['sponsors'] = $sponsors;
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['dateOfBirth'] = $sacrament['recipient_birth_date'] ?? null;
            $view['placeOfBirth'] = $sacrament['recipient_birth_place'] ?? null;
        } elseif (in_array($engineType, ['CONFIRMATION', 'CHRISMATION'], true)) {
            $view['recipientName'] = $first($byRole, ['recipient', 'candidate'], $sacrament['recipient_name'] ?? null);
            $view['sponsors'] = $byRole['sponsor'] ?? [];
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
        } elseif ($engineType === 'FIRST_HOLY_COMMUNION') {
            $view['recipientName'] = $first($byRole, ['recipient'], $sacrament['recipient_name'] ?? null);
            $view['eventSubtype'] = $sacrament['event_subtype'] ?? null;
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
        } elseif ($engineType === 'HOLY_MATRIMONY') {
            $groom = $this->spouseView($participants, 'groom', $sacrament, 'marriage_groom_');
            $bride = $this->spouseView($participants, 'bride', $sacrament, 'marriage_bride_');
            $view['groomName'] = $groom['fullName'];
            $view['brideName'] = $bride['fullName'];
            $view['groom'] = $groom;
            $view['bride'] = $bride;
            $view['groomParents'] = ['father' => $groom['fatherName'], 'mother' => $groom['motherName']];
            $view['brideParents'] = ['father' => $bride['fatherName'], 'mother' => $bride['motherName']];
            $view['witnesses'] = $byRole['witness'] ?? [];
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['ministerTitle'] = $sacrament['minister_title'] ?? null;
            $view['brideDiocese'] = $sacrament['marriage_bride_diocese_name'] ?? null;
            $view['groomDiocese'] = $sacrament['marriage_groom_diocese_name'] ?? null;
        } elseif ($engineType === 'ANOINTING_OF_THE_SICK') {
            $view['recipientName'] = $first($byRole, ['recipient'], $sacrament['recipient_name'] ?? null);
            $view['placeClassification'] = $sacrament['place_classification'] ?? null;
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['ministerTitle'] = $sacrament['minister_title'] ?? null;
        } elseif ($engineType === 'HOLY_ORDERS') {
            $typed = is_array($sacrament['typed_attributes'] ?? null) ? $sacrament['typed_attributes'] : [];
            $view['recipientName'] = $first($byRole, ['candidate', 'recipient'], $sacrament['recipient_name'] ?? null);
            $view['ordinationType'] = $typed['ordination_type'] ?? null;
            $view['dioceseName'] = $typed['diocese_name'] ?? null;
            $view['coConsecrators'] = $byRole['co_consecrator'] ?? [];
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['ministerTitle'] = $sacrament['minister_title'] ?? null;
        } elseif ($engineType === 'RECONCILIATION') {
            $view['recipientName'] = $first($byRole, ['recipient'], $sacrament['recipient_name'] ?? null);
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['ministerTitle'] = $sacrament['minister_title'] ?? null;
        } else {
            $view['recipientName'] = $first($byRole, ['recipient', 'candidate'], $sacrament['recipient_name'] ?? null);
            $view['ministerName'] = $first($byRole, ['minister'], $sacrament['minister_name'] ?? null);
            $view['extraFields'] = [];
        }

        return $view;
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @param  array<string, mixed>  $sacrament
     * @return array{fullName: ?string, baptismalStatusLabel: ?string, ecclesialAffiliationLabel: ?string, fatherName: ?string, motherName: ?string, parishResidence: ?string}
     */
    private function spouseView(array $participants, string $role, array $sacrament, string $prefix): array
    {
        $row = null;
        foreach ($participants as $participant) {
            if (strtolower((string) ($participant['role'] ?? '')) === $role) {
                $row = $participant;
                break;
            }
        }
        $snapshot = is_array($row) && is_array($row['snapshot'] ?? null) ? $row['snapshot'] : [];
        $affiliation = is_array($snapshot['affiliation'] ?? null) ? $snapshot['affiliation'] : [];

        $fullName = $snapshot['full_name']
            ?? (is_array($row) ? ($row['display_name'] ?? null) : null)
            ?? ($sacrament[$prefix.'full_name'] ?? null);

        $parish = $affiliation['parish_name']
            ?? (is_array($row) ? ($row['affiliation_parish_name'] ?? null) : null)
            ?? ($sacrament[$prefix.'church_name'] ?? null);
        $address = $snapshot['address']
            ?? ($sacrament[$prefix.'address'] ?? null)
            ?? ($sacrament[$prefix.'church_address'] ?? null);
        $residence = trim(implode(', ', array_filter([$parish, $address])));

        $statusParts = array_filter([
            $snapshot['baptismal_status_label'] ?? null,
            $snapshot['ecclesial_affiliation_label'] ?? null,
        ]);

        return [
            'fullName' => $fullName,
            'baptismalStatusLabel' => implode(' · ', $statusParts) ?: null,
            'ecclesialAffiliationLabel' => $snapshot['ecclesial_affiliation_label'] ?? null,
            'fatherName' => $snapshot['father_name'] ?? ($sacrament[$prefix.'father_name'] ?? null),
            'motherName' => $snapshot['mother_name'] ?? ($sacrament[$prefix.'mother_name'] ?? null),
            'parishResidence' => $residence !== '' ? $residence : null,
        ];
    }
}
