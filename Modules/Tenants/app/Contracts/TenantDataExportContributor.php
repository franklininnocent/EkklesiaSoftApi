<?php

namespace Modules\Tenants\Contracts;

/**
 * Module-owned export contributor. Knows its allowlisted columns and queries.
 * Must never SELECT * or emit passwords, tokens, or secrets.
 */
interface TenantDataExportContributor
{
    /**
     * Stable module key used in API selection and progress (e.g. users).
     */
    public function key(): string;

    /**
     * Human-readable label for Settings UI.
     */
    public function label(): string;

    /**
     * Whether the module checkbox is selected by default.
     */
    public function defaultSelected(): bool;

    /**
     * Approximate record count for UI estimates (cheap query).
     */
    public function estimateCount(int $tenantId): int;

    /**
     * Stream module data into one or more files via the writer factory.
     *
     * @param  callable(string $relativeFile, int $recordsSoFar): void  $onProgress
     * @return array{
     *     files: array<string, int>,
     *     warnings?: list<string>
     * }
     */
    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array;
}
