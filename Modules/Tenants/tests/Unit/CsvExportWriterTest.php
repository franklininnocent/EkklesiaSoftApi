<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Export\Writers\CsvExportWriter;
use PHPUnit\Framework\TestCase;

class CsvExportWriterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir().'/tenant-export-csv-'.uniqid('', true).'.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempPath)) {
            unlink($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_writes_utf8_bom_header_and_rows(): void
    {
        $writer = new CsvExportWriter($this->tempPath);
        $writer->writeHeader(['user_id', 'name', 'active']);
        $writer->writeRow([1, 'Ada Lovelace', true]);
        $writer->writeRow([2, 'Grace, Hopper', false]);
        $writer->finish();

        $contents = file_get_contents($this->tempPath);
        $this->assertNotFalse($contents);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertStringContainsString('user_id,name,active', $contents);
        $this->assertStringContainsString('Ada Lovelace', $contents);
        $this->assertStringContainsString('"Grace, Hopper"', $contents);
        $this->assertStringContainsString('yes', $contents);
        $this->assertStringContainsString('no', $contents);
        $this->assertStringNotContainsString('password', $contents);
    }

    public function test_requires_header_before_rows(): void
    {
        $this->expectException(\RuntimeException::class);

        $writer = new CsvExportWriter($this->tempPath, withBom: false);
        $writer->writeRow(['no-header']);
    }
}
