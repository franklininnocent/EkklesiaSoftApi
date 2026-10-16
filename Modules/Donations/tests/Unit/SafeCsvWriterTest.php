<?php

namespace Modules\Donations\Tests\Unit;

use Modules\Donations\Support\Reports\SafeCsvWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeCsvWriterTest extends TestCase
{
    #[Test]
    public function it_prefixes_formula_like_cells(): void
    {
        $this->assertSame("'=1+1", SafeCsvWriter::sanitizeCell('=1+1'));
        $this->assertSame("'+cmd", SafeCsvWriter::sanitizeCell('+cmd'));
        $this->assertSame("'@sum", SafeCsvWriter::sanitizeCell('@sum'));
    }

    #[Test]
    public function it_leaves_safe_cells_unchanged(): void
    {
        $this->assertSame('Smith Family', SafeCsvWriter::sanitizeCell('Smith Family'));
        $this->assertSame('100.00', SafeCsvWriter::sanitizeCell('100.00'));
    }
}
