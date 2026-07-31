<?php

namespace Modules\Donations\Services;

use Modules\Tenants\Models\Tenant;

class StewardshipReportPrintService
{
    public function __construct(private readonly DonationReportService $reportService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $report = $this->reportService->buildExecutiveNarrative($tenantId);

        return array_merge($report, [
            'organization' => [
                'name' => $tenant?->name ?? 'Parish',
                'printed_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderHtml(array $payload): string
    {
        $orgName = htmlspecialchars((string) ($payload['organization']['name'] ?? 'Parish'));
        $printedAt = htmlspecialchars((string) ($payload['organization']['printed_at'] ?? now()->toDateTimeString()));
        $title = htmlspecialchars((string) ($payload['title'] ?? 'Stewardship Summary'));
        $narrative = htmlspecialchars((string) ($payload['narrative'] ?? ''));
        $forecast = htmlspecialchars((string) ($payload['forecast_narrative'] ?? ''));

        $highlightsHtml = '';
        foreach ($payload['highlights'] ?? [] as $highlight) {
            $highlightsHtml .= '<li>' . htmlspecialchars((string) $highlight) . '</li>';
        }

        $actionsHtml = '';
        foreach ($payload['recommended_actions'] ?? [] as $action) {
            $actionsHtml .= '<li>' . htmlspecialchars((string) $action) . '</li>';
        }

        $metricsHtml = '';
        foreach ($payload['metrics'] ?? [] as $key => $value) {
            $label = htmlspecialchars(str_replace('_', ' ', (string) $key));
            $display = $value === null ? '—' : htmlspecialchars((string) $value);
            $metricsHtml .= "<tr><td>{$label}</td><td style=\"text-align:right\">{$display}</td></tr>";
        }

        $forecastBlock = $forecast !== ''
            ? "<section><h2>Forecast</h2><p>{$forecast}</p></section>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <style>
    body { font-family: Georgia, "Times New Roman", serif; color: #111827; margin: 24px; }
    .report { max-width: 760px; margin: 0 auto; border: 1px solid #d1d5db; padding: 28px; }
    h1 { margin: 0 0 4px; font-size: 1.5rem; }
    h2 { margin: 20px 0 8px; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; color: #4b5563; }
    .meta { color: #6b7280; font-size: 0.92rem; margin-bottom: 18px; }
    p { line-height: 1.55; }
    ul { margin: 0; padding-left: 1.2rem; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 4px; text-align: left; }
    @media print { body { margin: 0; } .report { border: 0; } }
  </style>
</head>
<body>
  <div class="report">
    <h1>{$orgName}</h1>
    <div class="meta">{$title} · Printed {$printedAt}</div>
    <section><h2>Overview</h2><p>{$narrative}</p></section>
    <section><h2>Highlights</h2><ul>{$highlightsHtml}</ul></section>
    <section><h2>Key Metrics</h2><table><tbody>{$metricsHtml}</tbody></table></section>
    {$forecastBlock}
    <section><h2>Recommended Actions</h2><ul>{$actionsHtml}</ul></section>
  </div>
</body>
</html>
HTML;
    }
}
