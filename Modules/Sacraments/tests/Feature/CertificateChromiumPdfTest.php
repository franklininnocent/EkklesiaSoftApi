<?php

namespace Modules\Sacraments\Tests\Feature;

use Modules\Sacraments\Services\Certificates\CertificateHtmlRenderer;
use Modules\Sacraments\Services\Certificates\ChromiumCertificatePdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateChromiumPdfTest extends TestCase
{
    #[Test]
    #[Group('chromium')]
    public function chromium_pdf_is_single_landscape_page_when_binary_exists(): void
    {
        $chromium = app(ChromiumCertificatePdfRenderer::class);
        if (! $chromium->isAvailable()) {
            $this->markTestSkipped('Chromium is not installed in this environment.');
        }

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
                'terminology' => ['dateLabel' => 'Date of Baptism', 'ministerLabel' => 'Parish Priest', 'sealLabel' => 'Seal'],
            ],
        ]);

        $pdf = $chromium->render($html);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf) ?: substr_count($pdf, '/Type /Page'));
    }
}
