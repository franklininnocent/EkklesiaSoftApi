<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Services\Certificates\CertificateHtmlRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateHtmlRendererTest extends TestCase
{
    #[Test]
    public function it_renders_layout_tokens_and_recipient_name_fit(): void
    {
        $html = app(CertificateHtmlRenderer::class)->render([
            'certificate_view' => [
                'sacramentType' => 'BAPTISM',
                'certificateTitle' => 'Certificate of Baptism',
                'subtitle' => 'Sacramental Register',
                'recipientName' => 'Maria Teresa Joseph',
                'dateOfEvent' => '2018-04-15',
                'paper' => 'A4',
                'themeId' => 'catholic',
                'church' => ['name' => 'St. Mary', 'denominationType' => 'ROMAN_CATHOLIC'],
                'registry' => ['certificateNumber' => 'B-1'],
                'issuedAt' => '2025-01-06',
                'terminology' => [
                    'recipientLabel' => 'This is to certify that',
                    'dateLabel' => 'Date of Baptism',
                    'ministerLabel' => 'Parish Priest',
                    'sealLabel' => 'Seal',
                    'registryBookLabel' => 'Book',
                    'registryPageLabel' => 'Page',
                    'certificateNumberLabel' => 'Certificate No.',
                    'issuedAtLabel' => 'Date of Issuance',
                ],
            ],
        ]);

        $this->assertStringContainsString('--cert-size-title:12mm', $html);
        $this->assertStringContainsString('--cert-spacer-max:18mm', $html);
        $this->assertStringContainsString('class="lower__spacer"', $html);
        $this->assertStringContainsString('style="font-size: 10mm"', $html);
        $this->assertStringContainsString('class="registry footer"', $html);
        $this->assertStringContainsString('Maria Teresa Joseph', $html);
    }

    #[Test]
    public function it_renders_matrimony_registry_footer_and_spouse_fields(): void
    {
        $html = app(CertificateHtmlRenderer::class)->render([
            'certificate_view' => [
                'sacramentType' => 'HOLY_MATRIMONY',
                'certificateTitle' => 'Certificate of Holy Matrimony',
                'subtitle' => 'Sacramental Register',
                'groomName' => 'Rohan Francis',
                'brideName' => 'Anna Maria D’Souza',
                'dateOfEvent' => '2024-12-28',
                'issuedAt' => '2025-01-06',
                'paper' => 'A4',
                'themeId' => 'catholic',
                'church' => ['name' => 'Sacred Heart Church', 'denominationType' => 'ROMAN_CATHOLIC'],
                'registry' => [
                    'bookNumber' => 'M-4',
                    'pageNumber' => '9',
                    'certificateNumber' => 'MAR-2024-009',
                ],
                'groom' => ['fullName' => 'Rohan Francis', 'fatherName' => 'Joseph Francis'],
                'bride' => ['fullName' => 'Anna Maria D’Souza', 'motherName' => 'Lina D’Souza'],
                'terminology' => [
                    'brideLabel' => 'Bride',
                    'groomLabel' => 'Groom',
                    'fatherLabel' => "Father's Name",
                    'motherLabel' => "Mother's Name",
                    'ministerLabel' => 'Celebrant',
                    'sealLabel' => 'Seal',
                    'registryBookLabel' => 'Book',
                    'registryPageLabel' => 'Page',
                    'certificateNumberLabel' => 'Certificate No.',
                    'issuedAtLabel' => 'Date of Issuance',
                ],
            ],
        ]);

        $this->assertStringContainsString('class="who matrimony"', $html);
        $this->assertStringContainsString('class="sp-field"', $html);
        $this->assertStringContainsString('class="registry footer"', $html);
        $this->assertStringContainsString('Date of Issuance:', $html);
        $this->assertStringContainsString('Book M-4', $html);
        $this->assertStringNotContainsString('class="amp"', $html);
    }
}
