<?php

namespace Modules\Donations\Tests\Unit;

use Modules\Donations\Support\MoneyMath;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyMathTest extends TestCase
{
    #[Test]
    public function it_adds_fractional_values_without_floating_point_drift(): void
    {
        $this->assertSame('0.30', MoneyMath::add('0.1', '0.2'));
    }

    #[Test]
    public function it_tracks_installment_balance_accurately(): void
    {
        $remaining = MoneyMath::subtract('50000.00', '15250.50');

        $this->assertSame('34749.50', $remaining);
    }

    #[Test]
    public function it_never_returns_negative_outstanding_balance(): void
    {
        $this->assertSame('0.00', MoneyMath::outstanding('100.00', '150.00'));
        $this->assertSame('25.50', MoneyMath::outstanding('100.00', '74.50'));
    }
}
