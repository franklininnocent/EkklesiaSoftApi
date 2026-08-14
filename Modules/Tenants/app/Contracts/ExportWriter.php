<?php

namespace Modules\Tenants\Contracts;

/**
 * Format-agnostic row writer for tenant data export files.
 * MVP: CsvExportWriter. Future: XlsxExportWriter without changing contributors.
 */
interface ExportWriter
{
    /**
     * @param  list<string>  $columns
     */
    public function writeHeader(array $columns): void;

    /**
     * @param  list<mixed>|array<string, mixed>  $values
     */
    public function writeRow(array $values): void;

    public function finish(): void;

    /**
     * Absolute filesystem path of the open file.
     */
    public function path(): string;
}
