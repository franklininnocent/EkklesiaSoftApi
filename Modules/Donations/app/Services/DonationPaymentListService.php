<?php

namespace Modules\Donations\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Http\Requests\IndexDonationPaymentsRequest;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\Reports\ReportPaymentQuery;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Support\ApiPagination;

final class DonationPaymentListService
{
    public function __construct(
        private readonly DonationLedgerService $ledgerService,
        private readonly ChurchCurrencyResolver $currencyResolver,
    ) {}

    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginate(int $tenantId, IndexDonationPaymentsRequest $request): array
    {
        $dateWindow = $request->resolvedDateWindow($tenantId);
        $filter = $request->toReportFilter();
        $filtered = ReportPaymentQuery::filtered($tenantId, $filter, [
            'apply_period' => false,
            'operational_search' => true,
        ]);
        $this->applyPaymentDateWindow($filtered, $dateWindow);

        $totals = $this->summarize($filtered);
        $pageQuery = ReportPaymentQuery::sorted(clone $filtered, $filter)
            ->with([
                'family' => fn ($family) => $family->where('tenant_id', $tenantId)
                    ->select(['id', 'family_name', 'family_code', 'bcc_id', 'tenant_id']),
                'allocations' => fn ($allocations) => $allocations->where('tenant_id', $tenantId),
                'receipt' => fn ($receipt) => $receipt->where('tenant_id', $tenantId)->where('is_void', false)->orderByDesc('created_at'),
                'receipts' => fn ($receipts) => $receipts->where('tenant_id', $tenantId),
            ]);

        $perPage = ApiPagination::clampFromRequest($request, 20);
        $paginator = $pageQuery->paginate($perPage);
        $paginator->getCollection()->transform(function (DonationPayment $payment) {
            if ($payment->relationLoaded('family') && $payment->family) {
                $payment->family->setAppends([]);
            }

            if ($payment->is_anonymous) {
                $payment->setRelation('family', null);
            }

            $payment->setAttribute('refundable_remaining', MoneyMath::toApiNumber(
                $this->ledgerService->refundableRemaining($payment)
            ));

            return $payment;
        });

        return [
            'paginator' => $paginator,
            'meta' => [
                'totals' => $totals + [
                    'currency_code' => $this->currencyResolver->currencyCodeForTenantId($tenantId) ?? '',
                ],
                'business_date' => $dateWindow['business_date'],
                'timezone' => DonationBusinessDate::timezoneForTenant($tenantId),
                'date_basis' => IndexDonationPaymentsRequest::DATE_BASIS,
                'date_mode' => $dateWindow['mode'],
            ],
        ];
    }

    /**
     * @param  Builder<DonationPayment>  $query
     * @param  array{mode: string, business_date: ?string, paid_from: ?string, paid_to: ?string}  $dateWindow
     */
    private function applyPaymentDateWindow(Builder $query, array $dateWindow): void
    {
        if (! empty($dateWindow['paid_from'])) {
            $query->whereDate('donation_payments.payment_date', '>=', $dateWindow['paid_from']);
        }
        if (! empty($dateWindow['paid_to'])) {
            $query->whereDate('donation_payments.payment_date', '<=', $dateWindow['paid_to']);
        }
    }

    /**
     * @param  Builder<DonationPayment>  $filtered
     * @return array{payment_count: int, collected_gross: float, refunded_total: float, net_collected: float, families_count: int}
     */
    private function summarize(Builder $filtered): array
    {
        $row = (clone $filtered)->reorder()->toBase()
            ->selectRaw('count(distinct donation_payments.id) as payment_count')
            ->selectRaw('count(distinct case when donation_payments.family_id is not null then donation_payments.family_id end) as families_count')
            ->selectRaw("coalesce(sum(case when donation_payments.status = 'succeeded' then donation_payments.amount else 0 end), 0) as collected_gross")
            ->selectRaw('coalesce(sum(donation_payments.refunded_amount), 0) as refunded_total')
            ->first();

        $collected = (string) ($row->collected_gross ?? 0);
        $refunded = (string) ($row->refunded_total ?? 0);

        return [
            'payment_count' => (int) ($row->payment_count ?? 0),
            'collected_gross' => MoneyMath::toApiNumber($collected),
            'refunded_total' => MoneyMath::toApiNumber($refunded),
            'net_collected' => MoneyMath::toApiNumber(MoneyMath::subtract($collected, $refunded)),
            'families_count' => (int) ($row->families_count ?? 0),
        ];
    }
}
