<?php

namespace Modules\Donations\Support\Reports;

/**
 * Normalized filter snapshot used by preview, export, and print.
 *
 * @phpstan-type FilterArray array<string, mixed>
 */
final class ReportFilter
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $reportType,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(string $reportType, array $data): self
    {
        $normalized = array_filter(
            $data,
            static fn ($value) => $value !== null && $value !== '',
        );

        return new self($reportType, $normalized);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    public function page(): int
    {
        return max(1, (int) $this->get('page', 1));
    }

    public function perPage(): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) $this->get('per_page', self::DEFAULT_PER_PAGE)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return array_merge(['report_type' => $this->reportType], $this->raw);
    }

    public function exportFormat(): string
    {
        return DonationReportExportFormat::normalize(
            is_string($this->get('export_format')) ? $this->get('export_format') : null,
        );
    }

    public function hashForIdempotency(int $tenantId, int $userId): string
    {
        $payload = json_encode([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'report_type' => $this->reportType,
            'export_format' => $this->exportFormat(),
            'filters' => $this->raw,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}
