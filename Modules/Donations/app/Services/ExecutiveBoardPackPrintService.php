<?php

namespace Modules\Donations\Services;

use Modules\Tenants\Models\Tenant;

class ExecutiveBoardPackPrintService
{
    public function __construct(
        private readonly DonationReportService $reportService,
        private readonly StewardshipReportPrintService $stewardshipPrintService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $executive = $this->reportService->buildExecutiveNarrative($tenantId);
        $comparison = $this->reportService->buildParishComparisonReport($tenantId);

        return [
            'organization' => [
                'name' => $tenant?->name ?? 'Parish',
                'printed_at' => now()->toDateTimeString(),
            ],
            'executive' => $executive,
            'parish_comparison' => $comparison,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderHtml(array $payload): string
    {
        $orgName = htmlspecialchars((string) ($payload['organization']['name'] ?? 'Parish'));
        $printedAt = htmlspecialchars((string) ($payload['organization']['printed_at'] ?? now()->toDateTimeString()));
        $executive = is_array($payload['executive'] ?? null) ? $payload['executive'] : [];
        $comparison = is_array($payload['parish_comparison'] ?? null) ? $payload['parish_comparison'] : [];

        $executivePage = $this->renderExecutivePage($orgName, $printedAt, $executive);
        $comparisonPage = ($comparison['available'] ?? false)
            ? $this->renderComparisonPage($orgName, $printedAt, $comparison)
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Executive Board Pack · {$orgName}</title>
  <style>
    body { font-family: Georgia, "Times New Roman", serif; color: #111827; margin: 24px; }
    .page { max-width: 760px; margin: 0 auto 32px; border: 1px solid #d1d5db; padding: 28px; page-break-after: always; }
    .page:last-child { page-break-after: auto; margin-bottom: 0; }
    h1 { margin: 0 0 4px; font-size: 1.5rem; }
    h2 { margin: 20px 0 8px; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; color: #4b5563; }
    .meta { color: #6b7280; font-size: 0.92rem; margin-bottom: 18px; }
    p { line-height: 1.55; }
    ul { margin: 0; padding-left: 1.2rem; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 4px; text-align: left; }
    th:last-child, td:last-child { text-align: right; }
    @media print { body { margin: 0; } .page { border: 0; margin: 0; } }
  </style>
</head>
<body>
  {$executivePage}
  {$comparisonPage}
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $executive
     */
    private function renderExecutivePage(string $orgName, string $printedAt, array $executive): string
    {
        $stewardshipPayload = array_merge($executive, [
            'organization' => [
                'name' => html_entity_decode($orgName, ENT_QUOTES),
                'printed_at' => html_entity_decode($printedAt, ENT_QUOTES),
            ],
        ]);

        $inner = $this->stewardshipPrintService->renderHtml($stewardshipPayload);
        $body = preg_replace('/^.*?<body[^>]*>/is', '', $inner) ?? $inner;
        $body = preg_replace('/<\/body>.*/is', '', $body) ?? $body;
        $body = preg_replace('/<div class="report">/i', '<div class="page">', $body, 1) ?? $body;

        return $body;
    }

    /**
     * @param array<string, mixed> $comparison
     */
    private function renderComparisonPage(string $orgName, string $printedAt, array $comparison): string
    {
        $title = htmlspecialchars((string) ($comparison['title'] ?? 'Parish Comparison Report'));
        $narrative = htmlspecialchars((string) ($comparison['narrative'] ?? ''));

        $highlightsHtml = '';
        foreach ($comparison['highlights'] ?? [] as $highlight) {
            $highlightsHtml .= '<li>' . htmlspecialchars((string) $highlight) . '</li>';
        }

        $rowsHtml = '';
        foreach ($comparison['parishes'] ?? [] as $parish) {
            $name = htmlspecialchars((string) ($parish['name'] ?? 'Parish'));
            $health = htmlspecialchars((string) (($parish['health_score'] ?? 0) . '/100 · ' . ($parish['health_label'] ?? '')));
            $participation = htmlspecialchars((string) (($parish['participation_rate'] ?? 0) . '%'));
            $collected = htmlspecialchars(number_format((float) ($parish['total_collected'] ?? 0), 2));
            $outstanding = htmlspecialchars(number_format((float) ($parish['pending_dues'] ?? 0), 2));
            $rowsHtml .= "<tr><td>{$name}</td><td>{$health}</td><td>{$participation}</td><td>{$collected}</td><td>{$outstanding}</td></tr>";
        }

        return <<<HTML
<div class="page">
  <h1>{$orgName}</h1>
  <div class="meta">{$title} · Printed {$printedAt}</div>
  <section><h2>Overview</h2><p>{$narrative}</p></section>
  <section><h2>Highlights</h2><ul>{$highlightsHtml}</ul></section>
  <section>
    <h2>Parish Comparison</h2>
    <table>
      <thead>
        <tr>
          <th>Parish</th>
          <th>Health</th>
          <th>Participation</th>
          <th>Collected</th>
          <th>Outstanding</th>
        </tr>
      </thead>
      <tbody>{$rowsHtml}</tbody>
    </table>
  </section>
</div>
HTML;
    }
}
