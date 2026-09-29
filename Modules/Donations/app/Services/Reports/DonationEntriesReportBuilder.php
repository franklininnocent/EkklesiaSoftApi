<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\Donation;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class DonationEntriesReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_DONATION_ENTRIES;
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
                'entry_count' => $total,
                'pledged_total' => MoneyMath::toApiNumber((clone $query)->sum('pledged_amount')),
                'collected_total' => MoneyMath::toApiNumber((clone $query)->sum('collected_amount')),
            ],
            'footer' => [
                'title' => $total.' offerings',
                'pledged_amount' => MoneyMath::toApiNumber((clone $query)->sum('pledged_amount')),
                'collected_amount' => MoneyMath::toApiNumber((clone $query)->sum('collected_amount')),
            ],
            'meta' => ['amount_basis' => 'balance', 'date_semantic' => 'received_at'],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['title', 'category', 'donor', 'family_id', 'financial_year', 'status', 'pledged_amount', 'collected_amount', 'received_at', 'is_anonymous'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $this->baseQuery($tenantId, $filter)->chunk(200, function ($rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                $mapped = $this->mapRow($row);
                SafeCsvWriter::putRow($handle, $this->mapRowToCsv($mapped));
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return Builder<Donation>
     */
    private function baseQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = Donation::query()
            ->forTenant($tenantId)
            ->with(['category:id,name', 'donor:id,name']);

        $period = $this->constrainedPeriod($tenantId, $filter);
        if ($period !== null) {
            $query->whereDate('received_at', '>=', $period['start'])
                ->whereDate('received_at', '<=', $period['end']);
        }

        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereHas('family', fn (Builder $family) => $bcc->applyToFamilyQuery($family, $tenantId));
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $likeOp = $this->likeOperator();
                $inner->where('title', $likeOp, $like)
                    ->orWhereHas('donor', fn (Builder $d) => $d->where('name', $likeOp, $like))
                    ->orWhereHas('family', fn (Builder $family) => $family->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
            });
        }

        return ReportTableSort::applyToQuery($query, $filter, [
            'title' => 'title',
            'status' => 'status',
            'pledged_amount' => 'pledged_amount',
            'collected_amount' => 'collected_amount',
            'received_at' => 'received_at',
        ], static function (Builder $builder): void {
            $builder->orderByDesc('received_at')->orderByDesc('id');
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'title', 'label' => 'Offering'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'donor', 'label' => 'Donor'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'pledged_amount', 'label' => 'Pledged'],
            ['key' => 'collected_amount', 'label' => 'Collected'],
            ['key' => 'received_at', 'label' => 'Received'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(Donation $row): array
    {
        return [
            'title' => $row->title,
            'category' => $row->category?->name,
            'donor' => $row->is_anonymous ? 'Anonymous' : ($row->donor?->name ?? ''),
            'family_id' => $row->family_id,
            'financial_year' => $row->financial_year,
            'status' => $row->status,
            'pledged_amount' => MoneyMath::toApiNumber($row->pledged_amount),
            'collected_amount' => MoneyMath::toApiNumber($row->collected_amount),
            'received_at' => $row->received_at?->format('Y-m-d'),
            'is_anonymous' => (bool) $row->is_anonymous,
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['title'],
            $row['category'],
            $row['donor'],
            $row['family_id'],
            $row['financial_year'],
            $row['status'],
            $row['pledged_amount'],
            $row['collected_amount'],
            $row['received_at'],
            $row['is_anonymous'] ? 'yes' : 'no',
        ];
    }
}
