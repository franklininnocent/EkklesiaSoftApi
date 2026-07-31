<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReportExport;

class DonationReportService
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly CollectionForecastService $forecastService,
        private readonly DioceseRollupDashboardService $rollupService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExecutiveNarrative(int $tenantId): array
    {
        $summary = $this->dashboardService->getSummary($tenantId);
        $forecast = $this->forecastService->build($tenantId, 3);
        $health = $summary['financial_health'] ?? [];
        $attentionCount = (int) ($summary['attention_summary']['count'] ?? 0);
        $monthCollected = (float) ($summary['period_collections']['current_month_collected'] ?? 0);
        $outstanding = (float) ($summary['totals']['pending_dues'] ?? 0);

        $highlights = [
            sprintf('Financial health is %s (%s/100).', strtolower($health['label'] ?? 'calculating'), $health['score'] ?? 0),
            sprintf('This month the parish collected %s.', number_format($monthCollected, 2)),
            sprintf('%s families still have %s outstanding.', $attentionCount, number_format($outstanding, 2)),
        ];

        $actions = [];
        if ($attentionCount > 0) {
            $actions[] = 'Review families requiring attention on the Financial Dashboard.';
        }
        if ($outstanding > 0) {
            $actions[] = 'Use Quick Collect during services to reduce outstanding balances.';
        }
        if (($forecast['signals']['collection_growth_pct'] ?? 0) < 0) {
            $actions[] = 'Collections are pacing below recent months — consider parish-wide reminders.';
        }
        if (empty($actions)) {
            $actions[] = 'Stewardship is on track. Share gratitude updates with participating families.';
        }

        return [
            'title' => 'Executive Stewardship Summary',
            'narrative' => $health['summary'] ?? 'Parish financial summary generated for leadership review.',
            'highlights' => $highlights,
            'recommended_actions' => $actions,
            'metrics' => [
                'health_score' => $health['score'] ?? null,
                'health_status' => $health['status'] ?? null,
                'current_month_collected' => $monthCollected,
                'pending_dues' => $outstanding,
                'participation_rate' => $summary['families']['participation_rate'] ?? 0,
                'forecast_projection' => $forecast['signals']['current_month_projection'] ?? 0,
            ],
            'forecast_narrative' => $forecast['narrative'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildParishComparisonReport(int $tenantId): array
    {
        $rollup = $this->rollupService->build($tenantId);

        if (!($rollup['available'] ?? false)) {
            return [
                'available' => false,
                'message' => $rollup['message'] ?? 'Parish comparison requires child parishes in the tenant hierarchy.',
            ];
        }

        $parishes = collect($rollup['parishes'] ?? []);
        $sorted = $parishes->sortByDesc('metrics.total_collected')->values();
        $top = $sorted->first();
        $needsAttention = $parishes->sortBy('health_score')->first();

        $highlights = [
            sprintf(
                'Diocese health is %s (%s/100) across %s parishes.',
                strtolower($rollup['financial_health']['label'] ?? 'calculating'),
                $rollup['financial_health']['score'] ?? 0,
                $rollup['scope']['parish_count'] ?? 0
            ),
            sprintf(
                'Consolidated collections total %s with %s outstanding.',
                number_format((float) ($rollup['consolidated']['total_collected'] ?? 0), 2),
                number_format((float) ($rollup['consolidated']['pending_dues'] ?? 0), 2)
            ),
        ];

        if ($top) {
            $highlights[] = sprintf(
                '%s leads collections at %s this period.',
                $top['name'] ?? 'Top parish',
                number_format((float) ($top['metrics']['total_collected'] ?? 0), 2)
            );
        }

        if ($needsAttention) {
            $highlights[] = sprintf(
                '%s needs the most attention with a health score of %s/100.',
                $needsAttention['name'] ?? 'A parish',
                $needsAttention['health_score'] ?? 0
            );
        }

        return [
            'available' => true,
            'title' => 'Parish Comparison Report',
            'narrative' => sprintf(
                '%s oversees %s parishes. Use this comparison to celebrate momentum and support parishes that need follow-up.',
                $rollup['root']['name'] ?? 'Diocese',
                $rollup['scope']['parish_count'] ?? 0
            ),
            'highlights' => $highlights,
            'consolidated' => $rollup['consolidated'] ?? [],
            'financial_health' => $rollup['financial_health'] ?? null,
            'parishes' => $sorted->map(fn (array $row) => [
                'tenant_id' => $row['tenant_id'] ?? null,
                'name' => $row['name'] ?? 'Parish',
                'health_score' => $row['health_score'] ?? 0,
                'health_label' => $row['health_label'] ?? '',
                'health_status' => $row['health_status'] ?? '',
                'participation_rate' => $row['participation_rate'] ?? 0,
                'total_collected' => $row['metrics']['total_collected'] ?? 0,
                'pending_dues' => $row['metrics']['pending_dues'] ?? 0,
                'current_month_collected' => $row['metrics']['current_month_collected'] ?? 0,
            ])->all(),
        ];
    }

    public function processExport(string $exportId): ?DonationReportExport
    {
        $export = DonationReportExport::find($exportId);
        if (!$export) {
            return null;
        }

        $export->status = 'processing';
        $export->save();

        try {
            $path = match ($export->report_type) {
                'donation_entries' => $this->buildDonationEntriesCsv($export->tenant_id),
                default => $this->buildPaymentsCsv($export->tenant_id),
            };
            $export->status = 'completed';
            $export->file_path = $path;
            $export->error_message = null;
            $export->save();
        } catch (\Throwable $exception) {
            $export->status = 'failed';
            $export->error_message = $exception->getMessage();
            $export->save();
        }

        return $export;
    }

    public function buildPaymentsCsv(int $tenantId): string
    {
        $directory = $this->ensureReportDirectory();
        $filename = sprintf('donation_payments_%d_%s.csv', $tenantId, now()->format('Ymd_His'));
        $path = $directory.'/'.$filename;

        $handle = fopen($path, 'w');
        fputcsv($handle, ['payment_number', 'payment_date', 'payer_name', 'method', 'status', 'amount', 'is_anonymous', 'source_type']);

        DonationPayment::forTenant($tenantId)->orderBy('payment_date')->chunk(200, function ($rows) use ($handle): void {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->payment_number,
                    $row->payment_date,
                    $row->payer_name,
                    $row->method,
                    $row->status,
                    $row->amount,
                    $row->is_anonymous ? 'yes' : 'no',
                    $row->source_type,
                ]);
            }
        });

        fclose($handle);

        return 'reports/'.$filename;
    }

    public function buildDonationEntriesCsv(int $tenantId): string
    {
        $directory = $this->ensureReportDirectory();
        $filename = sprintf('donation_entries_%d_%s.csv', $tenantId, now()->format('Ymd_His'));
        $path = $directory.'/'.$filename;

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'title',
            'category',
            'donor',
            'family_id',
            'financial_year',
            'status',
            'pledged_amount',
            'collected_amount',
            'received_at',
            'is_anonymous',
        ]);

        Donation::forTenant($tenantId)
            ->with(['category:id,name', 'donor:id,name'])
            ->orderByDesc('received_at')
            ->chunk(200, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->title,
                        $row->category?->name,
                        $row->is_anonymous ? 'Anonymous' : $row->donor?->name,
                        $row->family_id,
                        $row->financial_year,
                        $row->status,
                        $row->pledged_amount,
                        $row->collected_amount,
                        $row->received_at,
                        $row->is_anonymous ? 'yes' : 'no',
                    ]);
                }
            });

        fclose($handle);

        return 'reports/'.$filename;
    }

    private function ensureReportDirectory(): string
    {
        $directory = storage_path('app/reports');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
