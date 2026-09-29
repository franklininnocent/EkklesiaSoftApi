<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;

class FamilyParticipationDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'family_participation';

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics
    ) {}

    public function graphId(): string
    {
        return self::GRAPH_ID;
    }

    public function supportedDataElementIds(): array
    {
        return ['participating_families', 'not_participating'];
    }

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return match ($dataElementId) {
            'participating_families' => 'participating',
            'not_participating' => 'not_participating',
            default => null,
        };
    }

    public function supportedSliceIds(): array
    {
        return ['participating', 'not_participating'];
    }

    public function supportedSorts(): array
    {
        return [
            'family_name',
            'family_code',
            'bcc_name',
            'outstanding_amount',
            'due_count',
            'oldest_due_date',
        ];
    }

    public function build(int $tenantId, array $validated): array
    {
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $mapped = $this->sliceIdForElement($dataElementId);
        if ($mapped === null || $mapped !== $sliceId) {
            throw new \InvalidArgumentException('Invalid data element for this slice.');
        }

        $dashboardRange = $this->dashboardRange($tenantId, $validated);
        $active = $this->metrics->countActiveFamilies($tenantId);
        $participating = $this->metrics->countDistinctParticipatingFamilies($tenantId, $dashboardRange);
        $notParticipating = max(0, $active - $participating);
        $expectedCount = $sliceId === 'participating' ? $participating : $notParticipating;

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $bccFilter = isset($filters['bcc_id']) ? (string) $filters['bcc_id'] : '';
        $sort = (string) ($validated['sort'] ?? 'family_name');
        $direction = strtolower((string) ($validated['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $query = $this->scopedFamiliesQuery($tenantId, $sliceId, $dashboardRange);
        $this->applySearch($query, $search);
        $this->applyBccFilter($query, $bccFilter, $tenantId);

        $total = (int) (clone $query)->count();

        if (! in_array($sort, $this->supportedSorts(), true)) {
            throw new \InvalidArgumentException('Invalid sort field.');
        }

        $sortColumn = match ($sort) {
            'family_code' => 'families.family_code',
            'bcc_name' => 'bccs.name',
            'outstanding_amount' => 'due_agg.outstanding_amount',
            'due_count' => 'due_agg.due_count',
            'oldest_due_date' => 'due_agg.oldest_due_date',
            default => 'families.family_name',
        };

        $rows = $query->orderBy($sortColumn, $direction)
            ->forPage($page, $perPage)
            ->get();

        $items = $rows->map(fn ($row) => [
            'family_id' => $row->id,
            'family_name' => $row->family_name,
            'head_of_family' => $row->head_of_family,
            'family_code' => $row->family_code,
            'bcc_id' => $row->bcc_id,
            'bcc_name' => $row->bcc_name ?? 'Unassigned Area',
            'outstanding_amount' => MoneyMath::toApiNumber($row->outstanding_amount ?? 0),
            'due_count' => (int) ($row->due_count ?? 0),
            'oldest_due_date' => $row->oldest_due_date,
        ])->values()->all();

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => $dataElementId,
            'slice_id' => $sliceId,
            'dimension' => 'family',
            'record_kind' => 'family',
            'point_kind' => 'actual',
            'value_kind' => 'count',
            'title' => $sliceId === 'participating'
                ? 'Family participation → Participating'
                : 'Family participation → Not yet contributing',
            'why_this_number' => 'Active families with at least one successful payment in the last 90 days (through today).',
            'expected_amount' => 0,
            'expected_count' => $expectedCount,
            'workspace_path' => '/donations',
            'workspace_query' => [],
            'columns' => [
                ['key' => 'family', 'label' => 'Family'],
                ['key' => 'head', 'label' => 'Family head'],
                ['key' => 'bcc', 'label' => 'BCC / Community'],
            ],
            'supported_actions' => ['view_family', 'collect'],
        ]);

        return [
            'context' => $context,
            'summary' => [
                'family_count' => $total,
                'record_count' => $total,
                'due_count' => 0,
                'amount_total' => 0,
            ],
            'filter_options' => ['bccs' => []],
            'data' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'data' => $items,
            ],
        ];
    }

    private function scopedFamiliesQuery(int $tenantId, string $sliceId, ?DashboardDateRange $range = null): Builder
    {
        $window = $this->metrics->participationWindow($tenantId, $range);
        $businessDate = DonationBusinessDate::today($tenantId);
        $sumExpr = ContributionBalance::flooredOutstandingSumSqlExpression();

        $dueAggQuery = ContributionDue::forTenant($tenantId);
        ContributionBalance::scopeCollectable($dueAggQuery, $businessDate);
        $dueAggSub = $dueAggQuery
            ->selectRaw("family_id, {$sumExpr} as outstanding_amount, COUNT(*) as due_count, MIN(due_date) as oldest_due_date")
            ->groupBy('family_id')
            ->toBase();

        $query = Family::query()
            ->where('families.tenant_id', $tenantId)
            ->where('families.status', 'active')
            ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->leftJoinSub($dueAggSub, 'due_agg', 'due_agg.family_id', '=', 'families.id')
            ->select([
                'families.id',
                'families.family_name',
                'families.head_of_family',
                'families.family_code',
                'families.bcc_id',
                'bccs.name as bcc_name',
                DB::raw('COALESCE(due_agg.outstanding_amount, 0) as outstanding_amount'),
                DB::raw('COALESCE(due_agg.due_count, 0) as due_count'),
                'due_agg.oldest_due_date',
            ]);

        $exists = function ($inner) use ($tenantId, $window): void {
            $inner->selectRaw('1')
                ->from('donation_payments')
                ->whereColumn('donation_payments.family_id', 'families.id')
                ->where('donation_payments.tenant_id', $tenantId)
                ->where('donation_payments.status', 'succeeded')
                ->whereBetween('donation_payments.payment_date', [$window['start'], $window['end']]);
        };

        if ($sliceId === 'participating') {
            $query->whereExists($exists);
        } else {
            $query->whereNotExists($exists);
        }

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes(mb_substr($search, 0, 80), '%_\\').'%';
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query->where(function (Builder $inner) use ($term, $likeOp): void {
            $inner->where('families.family_name', $likeOp, $term)
                ->orWhere('families.head_of_family', $likeOp, $term)
                ->orWhere('families.family_code', $likeOp, $term);
        });
    }

    private function applyBccFilter(Builder $query, string $bccFilter, int $tenantId): void
    {
        if ($bccFilter === '') {
            return;
        }

        if ($bccFilter === 'unassigned') {
            $query->whereNull('families.bcc_id');

            return;
        }

        if (! DB::table('bccs')->where('id', $bccFilter)->where('tenant_id', $tenantId)->exists()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('families.bcc_id', $bccFilter);
    }
}
