<?php

namespace Modules\Tenants\Export\Contributors;

use Illuminate\Database\Eloquent\Builder;
use Modules\Tenants\Contracts\ExportWriterFactory;

trait WritesChunkedExportCsv
{
    /**
     * @param  list<string>  $headers
     * @param  callable(object): list<mixed>  $mapRow
     */
    protected function writeChunkedQuery(
        ExportWriterFactory $writers,
        string $relativePath,
        array $headers,
        Builder $query,
        int $chunkSize,
        callable $onProgress,
        callable $mapRow,
        string $column = 'id'
    ): int {
        $writer = $writers->create($relativePath);
        $writer->writeHeader($headers);

        $count = 0;
        $query
            ->orderBy($column)
            ->chunkById($chunkSize, function ($rows) use ($writer, &$count, $onProgress, $relativePath, $mapRow): void {
                foreach ($rows as $row) {
                    $writer->writeRow($mapRow($row));
                    $count++;
                }
                $onProgress($relativePath, $count);
            }, $column);

        $writer->finish();

        return $count;
    }

    protected function boolLabel(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
    }

    protected function activeLabel(mixed $value): string
    {
        return ((int) $value) === 1 ? 'active' : 'inactive';
    }
}
