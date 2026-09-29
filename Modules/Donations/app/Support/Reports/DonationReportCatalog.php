<?php

namespace Modules\Donations\Support\Reports;

use InvalidArgumentException;
use Modules\Donations\Services\Reports\AdjustmentsReportBuilder;
use Modules\Donations\Services\Reports\AllocationsReportBuilder;
use Modules\Donations\Services\Reports\CollectionsByMonthReportBuilder;
use Modules\Donations\Services\Reports\DisbursementsReportBuilder;
use Modules\Donations\Services\Reports\DonationEntriesReportBuilder;
use Modules\Donations\Services\Reports\DuesByPlanReportBuilder;
use Modules\Donations\Services\Reports\FamilyGivingReportBuilder;
use Modules\Donations\Services\Reports\OutstandingReportBuilder;
use Modules\Donations\Services\Reports\ParishComparisonExportBuilder;
use Modules\Donations\Services\Reports\ParticipationReportBuilder;
use Modules\Donations\Services\Reports\PaymentsReportBuilder;
use Modules\Donations\Services\Reports\ProjectFundingReportBuilder;
use Modules\Donations\Services\Reports\ReceiptsReportBuilder;

final class DonationReportCatalog
{
    public const TYPE_PAYMENTS = 'payments';

    public const TYPE_DONATION_ENTRIES = 'donation_entries';

    public const TYPE_RECEIPTS = 'receipts';

    public const TYPE_OUTSTANDING = 'outstanding';

    public const TYPE_PARTICIPATION = 'participation';

    public const TYPE_DUES_BY_PLAN = 'dues_by_plan';

    public const TYPE_PROJECT_FUNDING = 'project_funding';

    public const TYPE_FAMILY_GIVING = 'family_giving';

    public const TYPE_ALLOCATIONS = 'allocations';

    public const TYPE_ADJUSTMENTS = 'adjustments';

    public const TYPE_DISBURSEMENTS = 'disbursements';

    public const TYPE_COLLECTIONS_BY_MONTH = 'collections_by_month';

    public const TYPE_PARISH_COMPARISON = 'parish_comparison';

    /**
     * @return list<string>
     */
    public static function allTypes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     category: string,
     *     date_semantic: ReportDateSemantic,
     *     supported_filters: list<string>,
     *     requires_advanced: bool,
     *     preview: bool,
     *     export: bool,
     *     print: bool,
     *     default_preset: ?string,
     *     builder: class-string
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::TYPE_PAYMENTS => [
                'label' => 'Payment ledger',
                'category' => 'collect',
                'date_semantic' => ReportDateSemantic::PaymentPeriod,
                'default_preset' => 'this_month',
                'supported_filters' => ['date_from', 'date_to', 'preset', 'bcc_id', 'project_id', 'method', 'status', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => PaymentsReportBuilder::class,
            ],
            self::TYPE_DONATION_ENTRIES => [
                'label' => 'Offerings',
                'category' => 'collect',
                'date_semantic' => ReportDateSemantic::ReceivedAt,
                'supported_filters' => ['date_from', 'date_to', 'preset', 'fiscal_year', 'bcc_id', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => DonationEntriesReportBuilder::class,
            ],
            self::TYPE_RECEIPTS => [
                'label' => 'Receipt register',
                'category' => 'collect',
                'date_semantic' => ReportDateSemantic::ReceiptIssued,
                'supported_filters' => ['date_from', 'date_to', 'preset', 'bcc_id', 'status', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => ReceiptsReportBuilder::class,
            ],
            self::TYPE_OUTSTANDING => [
                'label' => 'Who still owes',
                'category' => 'follow_up',
                'date_semantic' => ReportDateSemantic::AsOf,
                'supported_filters' => ['as_of_date', 'bcc_id', 'project_id', 'plan_id', 'search', 'view'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => OutstandingReportBuilder::class,
            ],
            self::TYPE_PARTICIPATION => [
                'label' => 'Families who have not given',
                'category' => 'follow_up',
                'date_semantic' => ReportDateSemantic::ParticipationWindow,
                'default_preset' => 'last_90',
                'supported_filters' => ['date_from', 'date_to', 'preset', 'bcc_id', 'participation_status', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => ParticipationReportBuilder::class,
            ],
            self::TYPE_DUES_BY_PLAN => [
                'label' => 'Dues by plan',
                'category' => 'follow_up',
                'date_semantic' => ReportDateSemantic::FiscalYear,
                'supported_filters' => ['fiscal_year', 'date_from', 'date_to', 'plan_id', 'bcc_id'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => DuesByPlanReportBuilder::class,
            ],
            self::TYPE_PROJECT_FUNDING => [
                'label' => 'Project and campaign funding',
                'category' => 'projects',
                'date_semantic' => ReportDateSemantic::AsOf,
                'supported_filters' => ['as_of_date', 'project_id', 'entity_kind', 'bcc_id', 'view', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => ProjectFundingReportBuilder::class,
            ],
            self::TYPE_FAMILY_GIVING => [
                'label' => 'Family giving for the year',
                'category' => 'collect',
                'date_semantic' => ReportDateSemantic::FiscalYear,
                'supported_filters' => ['fiscal_year', 'bcc_id', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => FamilyGivingReportBuilder::class,
            ],
            self::TYPE_ALLOCATIONS => [
                'label' => 'Where payments went',
                'category' => 'books',
                'date_semantic' => ReportDateSemantic::PaymentPeriod,
                'supported_filters' => ['date_from', 'date_to', 'preset', 'bcc_id', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => AllocationsReportBuilder::class,
            ],
            self::TYPE_ADJUSTMENTS => [
                'label' => 'Refunds, reversals, and waivers',
                'category' => 'books',
                'date_semantic' => ReportDateSemantic::AdjustmentDate,
                'supported_filters' => ['date_from', 'date_to', 'preset', 'action_type', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => AdjustmentsReportBuilder::class,
            ],
            self::TYPE_DISBURSEMENTS => [
                'label' => 'Parish disbursements',
                'category' => 'books',
                'date_semantic' => ReportDateSemantic::ExpenseDate,
                'supported_filters' => ['date_from', 'date_to', 'preset', 'method', 'search'],
                'requires_advanced' => false,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => DisbursementsReportBuilder::class,
            ],
            self::TYPE_COLLECTIONS_BY_MONTH => [
                'label' => 'Collections by month',
                'category' => 'leadership',
                'date_semantic' => ReportDateSemantic::FiscalYear,
                'supported_filters' => ['fiscal_year', 'date_from', 'date_to', 'preset', 'bcc_id'],
                'requires_advanced' => true,
                'preview' => true,
                'export' => true,
                'print' => true,
                'builder' => CollectionsByMonthReportBuilder::class,
            ],
            self::TYPE_PARISH_COMPARISON => [
                'label' => 'Parish comparison',
                'category' => 'leadership',
                'date_semantic' => ReportDateSemantic::PaymentPeriod,
                'supported_filters' => [],
                'requires_advanced' => true,
                'preview' => false,
                'export' => true,
                'print' => true,
                'builder' => ParishComparisonExportBuilder::class,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $reportType): array
    {
        $definitions = self::definitions();
        if (! isset($definitions[$reportType])) {
            throw new InvalidArgumentException('This report is not available.');
        }

        return $definitions[$reportType];
    }

    /**
     * @return list<array{key: string, label: string, category: string, date_semantic: string, preview: bool, export: bool, print: bool, requires_advanced: bool, default_preset?: string}>
     */
    public static function catalogForApi(?string $tenantTier = null): array
    {
        $items = [];
        foreach (self::definitions() as $key => $def) {
            if ($key === self::TYPE_PARISH_COMPARISON && ! self::tierMayUseParishComparison($tenantTier)) {
                continue;
            }
            $item = [
                'key' => $key,
                'label' => $def['label'],
                'category' => $def['category'],
                'date_semantic' => $def['date_semantic']->value,
                'supported_filters' => $def['supported_filters'],
                'preview' => $def['preview'],
                'export' => $def['export'],
                'print' => $def['print'],
                'requires_advanced' => $def['requires_advanced'],
            ];
            if (! empty($def['default_preset'])) {
                $item['default_preset'] = $def['default_preset'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Parish comparison rolls up child parishes. Parish and branch tenants do not see it.
     */
    public static function tierMayUseParishComparison(?string $tenantTier): bool
    {
        $tier = strtolower(trim((string) $tenantTier));

        return in_array($tier, ['diocese', 'organization', 'platform'], true);
    }

    public static function assertSupportedFilters(string $reportType, ReportFilter $filter): void
    {
        $def = self::definition($reportType);
        $allowed = $def['supported_filters'];
        $ignore = ['page', 'per_page', 'sort', 'direction', 'report_type', 'export_format'];

        foreach (array_keys($filter->raw) as $key) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Filter "%s" is not supported for this report.', $key));
            }
        }
    }
}
