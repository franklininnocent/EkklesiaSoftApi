<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Contracts\DonationReportBuilderContract;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\SafeCsvWriter;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;

abstract class AbstractDonationReportBuilder implements DonationReportBuilderContract
{
    protected function parishToday(int $tenantId): string
    {
        return DonationBusinessDate::today($tenantId);
    }

    protected function currencyCode(int $tenantId): string
    {
        return app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId) ?? '';
    }

    protected function resolveDateRange(int $tenantId, ReportFilter $filter): ?DashboardDateRange
    {
        $preset = $filter->get('preset');
        $from = $filter->get('date_from');
        $to = $filter->get('date_to');

        if (($preset === null || $preset === '') && ($from === null || $from === '') && ($to === null || $to === '')) {
            return null;
        }

        return DashboardDateRange::resolve(
            $tenantId,
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            is_string($preset) ? $preset : null,
        );
    }

    protected function resolveBcc(int $tenantId, ReportFilter $filter): DashboardBccFilter
    {
        $bccId = $filter->get('bcc_id');

        return DashboardBccFilter::resolve($tenantId, $bccId);
    }

    protected function resolveProject(int $tenantId, ReportFilter $filter): DashboardProjectFilter
    {
        $projectId = $filter->get('project_id');

        return DashboardProjectFilter::resolve($tenantId, $projectId);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function meta(string $dateSemantic, string $amountBasis, array $extra = []): array
    {
        return array_merge([
            'date_semantic' => $dateSemantic,
            'amount_basis' => $amountBasis,
        ], $extra);
    }

    /**
     * @return array{start: string, end: string}|null Null when the filter is absent. An impossible range when the label cannot be parsed.
     */
    protected function fiscalYearBounds(int $tenantId, ReportFilter $filter): ?array
    {
        $raw = $filter->get('fiscal_year');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $bounds = app(ChurchFinancialPeriodResolver::class)->boundsForFiscalYearInput($tenantId, $raw);
        if ($bounds === null) {
            return ['start' => '9999-12-31', 'end' => '9999-12-31'];
        }

        return $bounds;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    protected function constrainedPeriod(int $tenantId, ReportFilter $filter): ?array
    {
        $range = $this->resolveDateRange($tenantId, $filter);
        $fiscal = $this->fiscalYearBounds($tenantId, $filter);
        $start = $range?->dateFrom;
        $end = $range?->collectionEnd;

        if ($fiscal !== null) {
            $start = $start === null ? $fiscal['start'] : max($start, $fiscal['start']);
            $end = $end === null ? $fiscal['end'] : min($end, $fiscal['end']);
        }

        if ($start === null || $end === null) {
            return null;
        }

        if ($start > $end) {
            return ['start' => '9999-12-31', 'end' => '9999-12-31'];
        }

        return ['start' => $start, 'end' => $end];
    }

    protected function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    protected function searchTerm(ReportFilter $filter): ?string
    {
        $search = $filter->get('search');
        if (! is_string($search)) {
            return null;
        }
        $trimmed = trim($search);
        if (mb_strlen($trimmed) < 2) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $rows
     */
    protected function writeCsvRows($handle, array $rows, callable $mapRow): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($rows as $row) {
            SafeCsvWriter::putRow($handle, $mapRow($row));
            $count++;
        }

        return $count;
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $filter = ReportFilter::fromValidated(
            $filter->reportType,
            array_merge($filter->raw, ['page' => 1, 'per_page' => 50000]),
        );
        $preview = $this->preview($tenantId, $filter);
        $rows = $preview['rows'];
        $count = $this->writeCsvRows($handle, $rows, fn (array $row) => $this->mapRowToCsv($row));
        $this->writeCsvSummary($handle, $preview);

        return $count;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  resource  $handle
     */
    protected function writeCsvSummary($handle, array $preview): void
    {
        $footer = $preview['footer'] ?? null;
        if (! is_array($footer) || $footer === []) {
            return;
        }

        $columns = $preview['columns'] ?? [];
        if (! is_array($columns) || $columns === []) {
            return;
        }

        $cells = [];
        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                $cells[] = '';

                continue;
            }
            $key = (string) ($column['key'] ?? '');
            if ($index === 0 && ! array_key_exists($key, $footer)) {
                $cells[] = 'Total';

                continue;
            }
            $value = $footer[$key] ?? '';
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $cells[] = is_scalar($value) ? $value : '';
        }

        SafeCsvWriter::putRow($handle, $cells);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    protected function sumMoneyColumns(array $rows, array $keys): array
    {
        $sums = [];
        foreach ($keys as $key) {
            $running = '0';
            foreach ($rows as $row) {
                $running = MoneyMath::add($running, $row[$key] ?? 0);
            }
            $sums[$key] = MoneyMath::toApiNumber($running);
        }

        return $sums;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<scalar|null>
     */
    abstract protected function mapRowToCsv(array $row): array;
}
