<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Support\LeadershipRoleNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LeadershipRoleNameNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('canonicalizeProvider')]
    public function canonicalize_collapses_whitespace_and_trims(string $input, string $expected): void
    {
        $this->assertSame($expected, LeadershipRoleNameNormalizer::canonicalize($input));
    }

    #[Test]
    #[DataProvider('normalizeProvider')]
    public function normalize_is_case_insensitive_and_whitespace_insensitive(string $input, string $expected): void
    {
        $this->assertSame($expected, LeadershipRoleNameNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalizeProvider(): array
    {
        return [
            'trim' => ['  Parish Priest  ', 'Parish Priest'],
            'collapse' => ['Parish   Priest', 'Parish Priest'],
            'empty' => ['   ', ''],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'lower' => ['Parish Priest', 'parish priest'],
            'upper' => ['PARISH PRIEST', 'parish priest'],
            'mixed whitespace' => [' Parish   Priest ', 'parish priest'],
        ];
    }
}
