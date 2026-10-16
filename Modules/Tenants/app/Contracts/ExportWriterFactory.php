<?php

namespace Modules\Tenants\Contracts;

/**
 * Creates ExportWriter instances for paths relative to an export working directory.
 */
interface ExportWriterFactory
{
    /**
     * @param  string  $relativePath  e.g. data/users.csv
     */
    public function create(string $relativePath): ExportWriter;
}
