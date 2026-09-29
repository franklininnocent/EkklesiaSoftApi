<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;

final class ReportPaymentQuery
{
    /**
     * @param  array{apply_period?: bool, operational_search?: bool}  $options
     * @return Builder<DonationPayment>
     */
    public static function filtered(int $tenantId, ReportFilter $filter, array $options = []): Builder
    {
        $query = DonationPayment::query()->forTenant($tenantId);

        $status = $filter->get('status');
        if (is_string($status) && $status !== '') {
            $query->where('donation_payments.status', $status);
        }

        $method = $filter->get('method');
        if (is_string($method) && $method !== '') {
            $query->where('donation_payments.method', $method);
        }

        $familyId = $filter->get('family_id');
        if (is_string($familyId) && $familyId !== '') {
            $query->where('donation_payments.family_id', $familyId);
        }

        if (($options['apply_period'] ?? true) === true) {
            self::applyPaymentPeriod($tenantId, $query, $filter);
        }

        $bcc = DashboardBccFilter::resolve($tenantId, $filter->get('bcc_id'));
        if ($bcc->isActive) {
            $query->whereHas('family', fn (Builder $family) => $bcc->applyToFamilyQuery($family, $tenantId));
        }

        $project = DashboardProjectFilter::resolve($tenantId, $filter->get('project_id'));
        if ($project->isActive) {
            $project->applyToDonationPaymentQuery($query, $tenantId);
        }

        self::applySearch($query, $tenantId, $filter, (bool) ($options['operational_search'] ?? false));

        return $query;
    }

    /**
     * @return Builder<DonationPayment>
     */
    public static function base(int $tenantId, ReportFilter $filter): Builder
    {
        $query = self::filtered($tenantId, $filter)
            ->with(['family:id,family_name,family_code,bcc_id', 'receipt' => fn ($q) => $q->where('is_void', false)->latest('issued_on')]);

        return self::sorted($query, $filter);
    }

    /**
     * @param  Builder<DonationPayment>  $query
     * @return Builder<DonationPayment>
     */
    public static function sorted(Builder $query, ReportFilter $filter): Builder
    {
        $allowed = [
            'payment_number',
            'payment_date',
            'family_name',
            'family_code',
            'payer_name',
            'method',
            'status',
            'amount',
            'refunded_amount',
            'source_type',
        ];
        $col = ReportTableSort::column($filter, $allowed);
        $dir = ReportTableSort::direction($filter);
        $query->reorder();

        if ($col === 'family_name' || $col === 'family_code') {
            $query->leftJoin('families as report_payment_families', 'report_payment_families.id', '=', 'donation_payments.family_id')
                ->select('donation_payments.*')
                ->orderBy('report_payment_families.'.$col, $dir)
                ->orderBy('donation_payments.id', $dir);

            return $query;
        }

        if ($col !== null) {
            $query->orderBy('donation_payments.'.$col, $dir)->orderBy('donation_payments.id', $dir);

            return $query;
        }

        return $query->orderByDesc('donation_payments.payment_date')->orderByDesc('donation_payments.id');
    }

    /**
     * @param  Builder<DonationPayment>  $query
     */
    public static function applyPaymentPeriod(int $tenantId, Builder $query, ReportFilter $filter): void
    {
        $range = DashboardDateRange::tryFromInput($tenantId, $filter->raw);
        if ($range !== null) {
            $query->whereDate('donation_payments.payment_date', '>=', $range->dateFrom)
                ->whereDate('donation_payments.payment_date', '<=', $range->collectionEnd);

            return;
        }

        $from = $filter->get('date_from');
        $to = $filter->get('date_to');
        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            $query->whereDate('donation_payments.payment_date', '>=', $from)
                ->whereDate('donation_payments.payment_date', '<=', $to);

            return;
        }

        if ($filter->get('preset') === 'today' || ($filter->get('today_only') === true || $filter->get('today_only') === '1')) {
            $today = DonationBusinessDate::today($tenantId);
            $query->whereDate('donation_payments.payment_date', $today);
        }
    }

    /**
     * @param  Builder<DonationPayment>  $query
     */
    public static function sumSucceededAmount(Builder $query): string
    {
        return (string) (clone $query)->where('donation_payments.status', 'succeeded')->sum('donation_payments.amount');
    }

    /**
     * @param  Builder<DonationPayment>  $query
     */
    private static function applySearch(Builder $query, int $tenantId, ReportFilter $filter, bool $operationalSearch): void
    {
        $search = trim((string) ($filter->get('search') ?? ''));
        if (mb_strlen($search) < 2) {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $inner) use ($like, $likeOp, $tenantId, $operationalSearch): void {
            if (! $operationalSearch) {
                $inner->where('donation_payments.payer_name', $likeOp, $like)
                    ->orWhere('donation_payments.payment_number', $likeOp, $like)
                    ->orWhereHas('family', function (Builder $family) use ($like, $likeOp, $tenantId): void {
                        $family->where('tenant_id', $tenantId)
                            ->where(function (Builder $name) use ($like, $likeOp): void {
                                $name->where('family_name', $likeOp, $like)
                                    ->orWhere('family_code', $likeOp, $like);
                            });
                    });

                return;
            }

            $inner->where(function (Builder $identified) use ($like, $tenantId): void {
                $identified->where('donation_payments.is_anonymous', false)
                    ->where(function (Builder $fields) use ($like, $tenantId): void {
                        self::whereEscapedLike($fields, 'donation_payments.payer_name', $like);
                        self::orWhereEscapedLike($fields, 'donation_payments.payment_number', $like);
                        self::orWhereEscapedLike($fields, 'donation_payments.gateway_reference', $like);
                        $fields->orWhereHas('family', function (Builder $family) use ($like, $tenantId): void {
                            $family->where('tenant_id', $tenantId)
                                ->where(function (Builder $name) use ($like): void {
                                    self::whereEscapedLike($name, 'family_name', $like);
                                    self::orWhereEscapedLike($name, 'family_code', $like);
                                });
                        })
                            ->orWhereHas('receipts', function (Builder $receipt) use ($like, $tenantId): void {
                                $receipt->where('tenant_id', $tenantId);
                                self::whereEscapedLike($receipt, 'receipt_number', $like);
                            });
                    });
            })->orWhere(function (Builder $anonymous) use ($like, $tenantId): void {
                $anonymous->where('donation_payments.is_anonymous', true)
                    ->where(function (Builder $fields) use ($like, $tenantId): void {
                        self::whereEscapedLike($fields, 'donation_payments.payment_number', $like);
                        self::orWhereEscapedLike($fields, 'donation_payments.gateway_reference', $like);
                        $fields->orWhereHas('receipts', function (Builder $receipt) use ($like, $tenantId): void {
                            $receipt->where('tenant_id', $tenantId);
                            self::whereEscapedLike($receipt, 'receipt_number', $like);
                        });
                    });
            });
        });
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function whereEscapedLike(Builder $query, string $column, string $like): void
    {
        $query->whereRaw(self::likeSql($column), [$like, '\\']);
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function orWhereEscapedLike(Builder $query, string $column, string $like): void
    {
        $query->orWhereRaw(self::likeSql($column), [$like, '\\']);
    }

    private static function likeSql(string $column): string
    {
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $column.' '.$operator.' ? ESCAPE ?';
    }
}
