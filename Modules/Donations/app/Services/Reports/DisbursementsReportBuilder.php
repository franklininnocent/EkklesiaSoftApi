<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\ParishExpense;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class DisbursementsReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_DISBURSEMENTS;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $query = $this->baseQuery($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $query->forPage($page, $perPage)->get()->map(fn ($row) => $this->mapRow($row))->all();

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'disbursement_count' => $total,
                'total_amount' => MoneyMath::toApiNumber((clone $query)->sum('amount')),
                'currency_code' => $this->currencyCode($tenantId),
            ],
            'footer' => [
                'expense_date' => $total.' disbursements',
                'amount' => MoneyMath::toApiNumber((clone $query)->sum('amount')),
            ],
            'meta' => [
                'amount_basis' => 'gross',
                'date_semantic' => 'expense_date',
                'disclaimer' => 'Recorded disbursements only — not a surplus or deficit.',
            ],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['expense_date', 'category', 'payee', 'method', 'amount', 'currency', 'notes'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $currency = $this->currencyCode($tenantId);
        $this->baseQuery($tenantId, $filter)->chunk(200, function ($rows) use ($handle, $currency, &$count): void {
            foreach ($rows as $row) {
                $mapped = $this->mapRow($row);
                SafeCsvWriter::putRow($handle, [
                    $mapped['expense_date'],
                    $mapped['category'],
                    $mapped['payee'],
                    $mapped['method'],
                    $mapped['amount'],
                    $currency,
                    $mapped['notes'],
                ]);
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return Builder<ParishExpense>
     */
    private function baseQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = ParishExpense::query()
            ->forTenant($tenantId);

        $range = $this->resolveDateRange($tenantId, $filter);
        if ($range !== null) {
            $query->whereDate('expense_date', '>=', $range->dateFrom)
                ->whereDate('expense_date', '<=', $range->collectionEnd);
        }

        $method = $filter->get('method');
        if (is_string($method) && $method !== '') {
            $query->where('method', $method);
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $likeOp = $this->likeOperator();
                $inner->where('payee', $likeOp, $like)->orWhere('category', $likeOp, $like);
            });
        }

        return ReportTableSort::applyToQuery($query, $filter, [
            'expense_date' => 'expense_date',
            'category' => 'category',
            'payee' => 'payee',
            'method' => 'method',
            'amount' => 'amount',
        ], static function (Builder $builder): void {
            $builder->orderByDesc('expense_date')->orderByDesc('id');
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'expense_date', 'label' => 'Date'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'payee', 'label' => 'Payee'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'amount', 'label' => 'Amount'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(ParishExpense $row): array
    {
        return [
            'expense_date' => $row->expense_date?->format('Y-m-d'),
            'category' => $row->category,
            'payee' => $row->payee,
            'method' => $row->method,
            'amount' => MoneyMath::toApiNumber($row->amount),
            'notes' => $row->notes,
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['expense_date'],
            $row['category'],
            $row['payee'],
            $row['method'],
            $row['amount'],
            '',
            $row['notes'],
        ];
    }
}
