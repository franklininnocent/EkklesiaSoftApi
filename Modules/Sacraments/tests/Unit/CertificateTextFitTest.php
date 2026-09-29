<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Certificates\CertificateLayoutTokens;
use Modules\Sacraments\Certificates\CertificateTextFit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateTextFitTest extends TestCase
{
    #[Test]
    public function it_keeps_short_names_at_ideal_size(): void
    {
        $fit = CertificateTextFit::fitRecipientName('Maria Teresa Joseph', 200);
        $this->assertEquals(CertificateLayoutTokens::SIZE_RECIPIENT, $fit['fontSizeMm']);
        $this->assertSame('OK', $fit['status']);
        $this->assertFalse($fit['overflow']);
    }

    #[Test]
    public function it_scales_very_long_names_within_safe_bounds(): void
    {
        $fit = CertificateTextFit::fitRecipientName(str_repeat('Alexander ', 12), 120);
        $this->assertLessThan(CertificateLayoutTokens::SIZE_RECIPIENT, $fit['fontSizeMm']);
        $this->assertGreaterThanOrEqual(
            CertificateLayoutTokens::SIZE_RECIPIENT * CertificateLayoutTokens::TEXT_FIT_MIN_SCALE,
            $fit['fontSizeMm']
        );
        $this->assertContains($fit['status'], ['SCALED', 'ERROR']);
    }

    #[Test]
    public function it_exposes_layout_tokens_as_css_variables(): void
    {
        $vars = CertificateLayoutTokens::cssVariables();
        $this->assertSame('12mm', $vars['--cert-size-title']);
        $this->assertSame('10mm', $vars['--cert-size-recipient']);
        $this->assertArrayHasKey('--cert-spacer-max', $vars);
    }
}
