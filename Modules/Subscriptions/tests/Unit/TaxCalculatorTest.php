<?php

namespace Modules\Subscriptions\Tests\Unit;

use Modules\Subscriptions\Support\TaxCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TaxCalculatorTest extends TestCase
{
    #[Test]
    public function exclusive_tax_is_added_on_top_of_the_net_price(): void
    {
        $result = TaxCalculator::breakdown('1499.00', '18.00', false);

        $this->assertSame('1499.00', $result['net']);
        $this->assertSame('269.82', $result['tax']);
        $this->assertSame('1768.82', $result['gross']);
    }

    #[Test]
    public function inclusive_tax_is_extracted_from_the_gross_price(): void
    {
        $result = TaxCalculator::breakdown('1180.00', '18.00', true);

        $this->assertSame('1000.00', $result['net']);
        $this->assertSame('180.00', $result['tax']);
        $this->assertSame('1180.00', $result['gross']);
    }

    #[Test]
    public function custom_pricing_has_no_breakdown(): void
    {
        $this->assertNull(TaxCalculator::breakdown(null, '18.00', false));
    }

    #[Test]
    public function half_cent_rounds_up(): void
    {
        // 0.25 * 18% = 0.045 → 0.05
        $this->assertSame('0.05', TaxCalculator::breakdown('0.25', '18.00', false)['tax']);
    }
}
