<?php

namespace Modules\EcclesiasticalData\Tests\Unit;

use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-create-modal
 * @group unit
 */
class BishopNameNormalizerTest extends TestCase
{
    private BishopNameNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = app(BishopNameNormalizer::class);
    }

    #[Test]
    public function it_strips_ecclesiastical_prefixes(): void
    {
        $this->assertSame('john smith', $this->normalizer->normalize('Most Rev. John Smith'));
        $this->assertSame('john smith', $this->normalizer->normalize('Archbishop John Smith'));
    }

    #[Test]
    public function it_returns_null_for_empty_values(): void
    {
        $this->assertNull($this->normalizer->normalize(null));
        $this->assertNull($this->normalizer->normalize('   '));
    }

    #[Test]
    public function it_collapses_whitespace_and_punctuation(): void
    {
        $this->assertSame(
            'fran ois o brien smith',
            $this->normalizer->normalize("  François   O'Brien-Smith  ")
        );
    }
}
