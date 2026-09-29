<?php

namespace Modules\Donations\Support;

use Modules\Donations\Services\ExecutiveReportMetricsService;

/**
 * Leadership Report metric identifiers. Query scopes and aggregation live in
 * {@see ExecutiveReportMetricsService} and domain services.
 */
final class ReportMetricCatalog
{
    /** @var array<int, string> */
    public const LEADERSHIP_GRAPH_IDS = [
        'outstanding_overdue',
        'collections',
        'collection_snapshot',
        'collection_trend',
        'family_participation',
        'month_end_forecast',
        'financial_health',
    ];

    public const PAYMENT_STATUS_SUCCEEDED = 'succeeded';

    public const PARTICIPATION_WINDOW_DAYS = 90;
}
