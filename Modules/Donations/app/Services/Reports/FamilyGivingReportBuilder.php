<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class FamilyGivingReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_FAMILY_GIVING;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $rows = ReportTableSort::sortArrayRows(
            $this->aggregateRows($tenantId, $filter),
            $filter,
            ['family_code', 'family_name', 'collected_total'],
        );
        $total = count($rows);
        $page = $filter->page();
        $perPage = $filter->perPage();
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $collected = $this->sumMoneyColumns($rows, ['collected_total'])['collected_total'];

        return [
            'columns' => $this->columns(),
            'rows' => $slice,
            'totals' => [
                'family_count' => $total,
                'collected_total' => $collected,
            ],
            'footer' => [
                'family_code' => $total.' families',
                'collected_total' => $collected,
            ],
            'meta' => $this->meta('fiscal_year', 'gross'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['family_code', 'family_name', 'collected_total'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($this->aggregateRows($tenantId, $filter) as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(int $tenantId, ReportFilter $filter): array
    {
        $bounds = $this->fyBounds($tenantId, $filter);
        $bcc = $this->resolveBcc($tenantId, $filter);

        $query = DB::table('donation_payments as pay')
            ->join('families as f', 'f.id', '=', 'pay.family_id')
            ->where('pay.tenant_id', $tenantId)
            ->where('pay.status', 'succeeded')
            ->whereNull('pay.deleted_at')
            ->whereDate('pay.payment_date', '>=', $bounds['start'])
            ->whereDate('pay.payment_date', '<=', $bounds['end'])
            ->selectRaw('f.id as family_id, f.family_code, f.family_name, SUM(pay.amount) as collected_total')
            ->groupBy('f.id', 'f.family_code', 'f.family_name')
            ->orderBy('f.family_name');

        if ($bcc->isActive) {
            if ($bcc->unassignedOnly) {
                $query->whereNull('f.bcc_id');
            } elseif ($bcc->bccId !== null) {
                $query->where('f.bcc_id', $bcc->bccId);
            }
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('f.family_name', $this->likeOperator(), $like)->orWhere('f.family_code', $this->likeOperator(), $like);
            });
        }

        return collect($query->get())->map(fn ($row) => [
            'family_id' => $row->family_id,
            'family_code' => $row->family_code,
            'family_name' => $row->family_name,
            'collected_total' => MoneyMath::toApiNumber((string) $row->collected_total),
        ])->all();
    }

    /**
     * @return array{start: string, end: string}
     */
    private function fyBounds(int $tenantId, ReportFilter $filter): array
    {
        $fiscal = $this->fiscalYearBounds($tenantId, $filter);
        if ($fiscal !== null) {
            return $fiscal;
        }

        $range = $this->resolveDateRange($tenantId, $filter);
        if ($range !== null) {
            return ['start' => $range->dateFrom, 'end' => $range->collectionEnd];
        }

        return DonationBusinessDate::currentFinancialYearBounds($tenantId);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'family_code', 'label' => 'Family ID'],
            ['key' => 'family_name', 'label' => 'Family'],
            ['key' => 'collected_total', 'label' => 'Collected'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [$row['family_code'], $row['family_name'], $row['collected_total']];
    }
}
