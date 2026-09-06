<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Certificates\CertificateThemeCatalog;
use Modules\Sacraments\Certificates\CertificateViewAssembler;
use Modules\Sacraments\Certificates\DenominationMapper;
use Modules\Sacraments\Certificates\LiturgicalTerminologyCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateEngineMapperTest extends TestCase
{
    #[Test]
    public function it_maps_church_denomination_codes(): void
    {
        $this->assertSame(DenominationMapper::ROMAN_CATHOLIC, DenominationMapper::map('CATHOLIC'));
        $this->assertSame(DenominationMapper::EASTERN_RITE, DenominationMapper::map('SYRO_MALABAR'));
        $this->assertSame(DenominationMapper::CSI, DenominationMapper::map('CSI'));
        $this->assertSame(DenominationMapper::ANGLICAN, DenominationMapper::map('ANGLICAN'));
        $this->assertSame('csi', DenominationMapper::themeId(DenominationMapper::ANGLICAN));
        $this->assertSame('generic', DenominationMapper::themeId(DenominationMapper::LUTHERAN));
        $this->assertSame('CHRISMATION', DenominationMapper::engineSacramentType('CONFIRMATION', DenominationMapper::EASTERN_RITE));
        $this->assertSame('FIRST_HOLY_COMMUNION', DenominationMapper::engineSacramentType('EUCHARIST', DenominationMapper::CSI));
    }

    #[Test]
    public function it_uses_csi_presbyter_and_catholic_godparents(): void
    {
        $csi = LiturgicalTerminologyCatalog::for('CSI', 'BAPTISM');
        $catholic = LiturgicalTerminologyCatalog::for('ROMAN_CATHOLIC', 'BAPTISM');
        $this->assertSame('Presbyter', $csi['ministerLabel']);
        $this->assertSame('Sponsors', $csi['sponsorsLabel']);
        $this->assertSame('Godparents', $catholic['sponsorsLabel']);
        $this->assertSame('Parish Priest', $catholic['ministerLabel']);
    }

    #[Test]
    public function it_assembles_baptism_view_and_omits_blank_sponsors(): void
    {
        $view = (new CertificateViewAssembler)->assemble([
            'locale' => 'en',
            'sacrament' => [
                'type_code' => 'BAPTISM',
                'date_administered' => '2018-04-15',
                'recipient_name' => 'Anna Recipient',
                'certificate_number' => 'B-1',
            ],
            'participants' => [
                ['role' => 'recipient', 'display_name' => 'Anna Recipient'],
                ['role' => 'minister', 'display_name' => 'Fr. Thomas'],
            ],
            'church' => [
                'name' => 'St. Mary',
                'denomination_code' => 'CATHOLIC',
                'denomination_type' => 'ROMAN_CATHOLIC',
            ],
            'render' => [
                'theme_id' => 'catholic',
                'paper' => 'A4',
                'emblem' => 'CHI_RHO',
            ],
        ]);

        $this->assertSame('BAPTISM', $view['sacramentType']);
        $this->assertSame('Anna Recipient', $view['recipientName']);
        $this->assertSame([], $view['sponsors']);
        $this->assertSame('catholic', $view['themeId']);
    }

    #[Test]
    public function it_never_puts_a_parish_logo_on_the_certificate_view(): void
    {
        $view = (new CertificateViewAssembler)->assemble([
            'locale' => 'en',
            'sacrament' => [
                'type_code' => 'BAPTISM',
                'recipient_name' => 'Anna Recipient',
            ],
            'participants' => [
                ['role' => 'recipient', 'display_name' => 'Anna Recipient'],
            ],
            'church' => [
                'name' => 'St. Mary',
                'logo_url' => 'tenants/47/logos/logo_t47_20251102033121_6HARi0X15JwJ8MLq_a83c170e.jpg',
                'logo_data_uri' => 'data:image/jpeg;base64,AAAA',
                'denomination_code' => 'CATHOLIC',
                'denomination_type' => 'ROMAN_CATHOLIC',
            ],
            'render' => [
                'theme_id' => 'catholic',
                'paper' => 'A4',
            ],
        ]);

        $this->assertArrayNotHasKey('logoDataUri', $view['church']);
        $this->assertNull($view['church']['logoUrl']);
    }

    #[Test]
    public function it_assembles_matrimony_spouses_and_omits_register_only_fields(): void
    {
        $view = (new CertificateViewAssembler)->assemble([
            'locale' => 'en',
            'issued_at' => '2026-09-03T10:00:00Z',
            'sacrament' => [
                'type_code' => 'MATRIMONY',
                'date_administered' => '2026-08-10',
                'registry_entry' => 'LM-12',
                'certificate_number' => 'M-1',
                'marriage_canonical_classification' => 'mixed_marriage',
            ],
            'participants' => [
                [
                    'role' => 'groom',
                    'display_name' => 'Joseph Francis',
                    'snapshot' => [
                        'full_name' => 'Joseph Francis',
                        'father_name' => 'Francis Xavier',
                        'mother_name' => 'Mary Xavier',
                        'baptismal_status_label' => 'Baptized Catholic',
                    ],
                ],
                [
                    'role' => 'bride',
                    'display_name' => 'Maria Teresa',
                    'snapshot' => [
                        'full_name' => 'Maria Teresa',
                        'father_name' => 'Thomas Joseph',
                        'mother_name' => 'Anna Joseph',
                        'baptismal_status_label' => 'Baptized Catholic',
                    ],
                ],
                ['role' => 'witness', 'display_name' => 'Peter D’Souza'],
                ['role' => 'witness', 'display_name' => 'Agnes Fernandez'],
                ['role' => 'minister', 'display_name' => 'Fr. Thomas'],
            ],
            'church' => [
                'name' => 'Sacred Heart Church',
                'diocese' => 'Diocese of Kuzhithurai',
                'denomination_code' => 'CATHOLIC',
                'denomination_type' => 'ROMAN_CATHOLIC',
            ],
            'render' => [
                'theme_id' => 'catholic',
                'paper' => 'A4',
                'emblem' => 'CHI_RHO',
            ],
        ]);

        $this->assertSame('HOLY_MATRIMONY', $view['sacramentType']);
        $this->assertSame('Joseph Francis', $view['groom']['fullName']);
        $this->assertSame('Maria Teresa', $view['bride']['fullName']);
        $this->assertSame('Baptized Catholic', $view['groom']['baptismalStatusLabel']);
        $this->assertSame('Francis Xavier', $view['groom']['fatherName']);
        $this->assertSame('Anna Joseph', $view['bride']['motherName']);
        $this->assertSame(['Peter D’Souza', 'Agnes Fernandez'], $view['witnesses']);
        $this->assertSame('LM-12', $view['registry']['registryEntry']);
        $this->assertSame('2026-09-03T10:00:00Z', $view['issuedAt']);
        $this->assertSame('Diocese of Kuzhithurai', $view['church']['diocese']);
        $this->assertArrayNotHasKey('classification', $view);
        $this->assertArrayNotHasKey('dispensations', $view);
        $this->assertArrayNotHasKey('canonical_annotations', $view);
        $this->assertArrayNotHasKey('marriage_canonical_classification', $view);
    }

    #[Test]
    public function it_builds_matrimony_parish_residence_from_church_address_fallback(): void
    {
        $view = (new CertificateViewAssembler)->assemble([
            'locale' => 'en',
            'sacrament' => [
                'type_code' => 'MATRIMONY',
                'date_administered' => '2026-08-10',
                'marriage_groom_full_name' => 'Rohan Francis',
                'marriage_groom_church_name' => 'Sacred Heart Church',
                'marriage_groom_church_address' => 'Kadayal',
            ],
            'participants' => [],
            'church' => [
                'name' => 'Sacred Heart Church',
                'diocese' => 'Diocese of Kuzhithurai',
                'denomination_code' => 'CATHOLIC',
                'denomination_type' => 'ROMAN_CATHOLIC',
            ],
            'render' => [
                'theme_id' => 'catholic',
                'paper' => 'A4',
                'emblem' => 'CHI_RHO',
            ],
        ]);

        $this->assertSame('Sacred Heart Church, Kadayal', $view['groom']['parishResidence']);
        $this->assertSame('Diocese of Kuzhithurai', $view['church']['diocese']);
    }

    #[Test]
    public function it_always_uses_canonical_chi_rho_emblem_even_for_legacy_projection_emblems(): void
    {
        $view = (new CertificateViewAssembler)->assemble([
            'locale' => 'en',
            'sacrament' => [
                'type_code' => 'EUCHARIST',
                'date_administered' => '2020-05-01',
                'recipient_name' => 'Lucy Communicant',
            ],
            'participants' => [
                ['role' => 'recipient', 'display_name' => 'Lucy Communicant'],
            ],
            'church' => [
                'name' => 'St. Mary',
                'denomination_code' => 'CATHOLIC',
                'denomination_type' => 'ROMAN_CATHOLIC',
            ],
            'render' => [
                'theme_id' => 'catholic',
                'paper' => 'A4',
                'emblem' => 'SACRED_HEART',
            ],
        ]);

        $this->assertSame('FIRST_HOLY_COMMUNION', $view['sacramentType']);
        $this->assertSame(CertificateThemeCatalog::CANONICAL_EMBLEM, $view['emblem']);
    }
}
