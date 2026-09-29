<?php

namespace Modules\Tenants\Export\Writers;

use Modules\Tenants\Contracts\ExportWriter;
use RuntimeException;

/**
 * UTF-8 CSV writer with Excel-friendly BOM and proper fputcsv escaping.
 */
class CsvExportWriter implements ExportWriter
{
    /** @var resource|null */
    private $handle = null;

    private bool $headerWritten = false;

    private string $path;

    public function __construct(string $absolutePath, bool $withBom = true)
    {
        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create export directory.');
        }

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open export CSV for writing.');
        }

        $this->handle = $handle;
        $this->path = $absolutePath;

        if ($withBom) {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }
    }

    public function writeHeader(array $columns): void
    {
        $this->assertOpen();
        fputcsv($this->handle, array_values($columns));
        $this->headerWritten = true;
    }

    public function writeRow(array $values): void
    {
        $this->assertOpen();

        if (! $this->headerWritten) {
            throw new RuntimeException('CSV header must be written before rows.');
        }

        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = $this->normalizeValue($value);
        }

        fputcsv($this->handle, $normalized);
    }

    public function finish(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->finish();
    }

    private function assertOpen(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CSV writer is already closed.');
        }
    }

    private function normalizeValue(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) $value;
    }
}
