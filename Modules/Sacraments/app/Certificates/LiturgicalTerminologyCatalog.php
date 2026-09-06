<?php

namespace Modules\Sacraments\Certificates;

final class LiturgicalTerminologyCatalog
{
    /**
     * @return array<string, string>
     */
    public static function for(string $denominationType, string $sacramentType): array
    {
        $generic = [
            'sacramentTitle' => 'Sacramental Certificate',
            'subtitle' => 'Church Register',
            'recipientLabel' => 'Recipient',
            'dateLabel' => 'Date',
            'placeLabel' => 'Place',
            'ministerLabel' => 'Minister',
            'registrarLabel' => 'Registrar',
            'sponsorsLabel' => 'Sponsors',
            'fatherLabel' => 'Father',
            'motherLabel' => 'Mother',
            'brideLabel' => 'Bride',
            'groomLabel' => 'Groom',
            'witnessesLabel' => 'Witnesses',
            'sealLabel' => 'Church Seal',
            'registryBookLabel' => 'Book',
            'registryPageLabel' => 'Page',
            'certificateNumberLabel' => 'Certificate number',
            'registryEntryLabel' => 'Entry No.',
            'issuedAtLabel' => 'Date of Issuance',
            'nameAsRecordedLabel' => 'Name as recorded',
            'baptismalStatusLabel' => 'Baptismal status',
            'parishResidenceLabel' => 'Parish / residence',
            'witness1Label' => 'Witness 1',
            'witness2Label' => 'Witness 2',
            'dateOfBirthLabel' => 'Date of birth',
            'placeOfBirthLabel' => 'Place of birth',
            'verifyHint' => 'Scan to confirm this certificate',
            'issuedNotice' => 'Issued from the parish sacramental register',
        ];

        $catholicBaptism = array_merge($generic, [
            'sacramentTitle' => 'Certificate of Baptism',
            'subtitle' => 'Sacramental Register',
            'recipientLabel' => 'Child’s full name',
            'dateLabel' => 'Date of Baptism',
            'placeLabel' => 'Place of Baptism',
            'ministerLabel' => 'Parish Priest',
            'registrarLabel' => 'Parish Registrar',
            'sponsorsLabel' => 'Godparents',
            'sealLabel' => 'Parish Seal',
        ]);

        $map = [
            'ROMAN_CATHOLIC:BAPTISM' => $catholicBaptism,
            'ROMAN_CATHOLIC:HOLY_MATRIMONY' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Holy Matrimony',
                'dateLabel' => 'Date of Marriage',
                'placeLabel' => 'Place of Marriage',
                'ministerLabel' => 'Celebrant',
                'motherLabel' => "Mother's Name",
                'certificateNumberLabel' => 'Certificate No.',
            ]),
            'ROMAN_CATHOLIC:CONFIRMATION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Confirmation',
                'recipientLabel' => 'Confirmand',
                'dateLabel' => 'Date of Confirmation',
                'placeLabel' => 'Place of Confirmation',
                'sponsorsLabel' => 'Sponsor',
                'ministerLabel' => 'Bishop / Minister',
            ]),
            'ROMAN_CATHOLIC:CHRISMATION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Chrismation',
                'recipientLabel' => 'Confirmand',
                'dateLabel' => 'Date of Chrismation',
                'placeLabel' => 'Place of Chrismation',
            ]),
            'ROMAN_CATHOLIC:FIRST_HOLY_COMMUNION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of First Holy Communion',
                'recipientLabel' => 'Communicant',
                'dateLabel' => 'Date of First Holy Communion',
                'placeLabel' => 'Place of First Holy Communion',
            ]),
            'CSI:BAPTISM' => array_merge($catholicBaptism, [
                'subtitle' => 'Church of South India',
                'ministerLabel' => 'Presbyter',
                'registrarLabel' => 'Church Secretary',
                'sponsorsLabel' => 'Sponsors',
                'sealLabel' => 'Church Seal',
            ]),
            'CSI:HOLY_MATRIMONY' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Holy Matrimony',
                'subtitle' => 'Church of South India',
                'dateLabel' => 'Date of Marriage',
                'placeLabel' => 'Place of Marriage',
                'ministerLabel' => 'Presbyter',
                'sealLabel' => 'Church Seal',
                'motherLabel' => "Mother's Name",
                'certificateNumberLabel' => 'Certificate No.',
            ]),
            'CSI:CONFIRMATION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Confirmation',
                'subtitle' => 'Church of South India',
                'recipientLabel' => 'Confirmand',
                'sponsorsLabel' => 'Sponsors',
                'ministerLabel' => 'Bishop / Presbyter',
                'sealLabel' => 'Church Seal',
            ]),
            'CSI:FIRST_HOLY_COMMUNION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of First Communion',
                'subtitle' => 'Church of South India',
                'recipientLabel' => 'Communicant',
                'ministerLabel' => 'Presbyter',
                'sealLabel' => 'Church Seal',
            ]),
            'ANGLICAN:BAPTISM' => array_merge($catholicBaptism, [
                'subtitle' => 'Anglican Communion',
                'ministerLabel' => 'Priest',
                'sponsorsLabel' => 'Sponsors',
            ]),
            'LUTHERAN:BAPTISM' => array_merge($catholicBaptism, [
                'subtitle' => 'Parish Register',
                'ministerLabel' => 'Pastor',
                'sponsorsLabel' => 'Sponsors',
                'sealLabel' => 'Church Seal',
            ]),
            'NON_DENOM:HOLY_MATRIMONY' => array_merge($generic, [
                'sacramentTitle' => 'Certificate of Holy Matrimony',
                'brideLabel' => 'Spouse',
                'groomLabel' => 'Spouse',
                'motherLabel' => "Mother's Name",
                'certificateNumberLabel' => 'Certificate No.',
            ]),
            'EASTERN_RITE:CHRISMATION' => array_merge($catholicBaptism, [
                'sacramentTitle' => 'Certificate of Chrismation',
                'dateLabel' => 'Date of Chrismation',
                'placeLabel' => 'Place of Chrismation',
                'ministerLabel' => 'Celebrant',
            ]),
        ];

        $key = $denominationType.':'.$sacramentType;

        return $map[$key] ?? $generic;
    }
}
