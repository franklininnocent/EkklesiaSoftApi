<?php

namespace Modules\Donations\Services;

use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;

class FamilyStatementPrintService
{
    public function __construct(private readonly FamilyFinancialProfileService $profileService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(int $tenantId, string $familyId): array
    {
        $profile = $this->profileService->build($tenantId, $familyId);
        $family = Family::query()->findOrFail($familyId);
        $tenant = Tenant::query()->find($tenantId);

        return array_merge($profile, [
            'family' => [
                'id' => $family->id,
                'name' => $family->family_name,
                'code' => $family->family_code,
                'head_of_family' => $family->head_of_family,
            ],
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
        $familyName = htmlspecialchars((string) ($payload['family']['name'] ?? 'Family'));
        $familyCode = htmlspecialchars((string) ($payload['family']['code'] ?? ''));
        $head = htmlspecialchars((string) ($payload['family']['head_of_family'] ?? ''));
        $health = $payload['financial_health'] ?? [];
        $totals = $payload['totals'] ?? [];
        $insights = $payload['ai_insights'] ?? [];
        $actions = $payload['recommended_actions'] ?? [];

        $insightsHtml = '';
        foreach ($insights as $insight) {
            $insightsHtml .= '<li>' . htmlspecialchars((string) $insight) . '</li>';
        }

        $actionsHtml = '';
        foreach ($actions as $action) {
            $actionsHtml .= '<li>' . htmlspecialchars((string) $action) . '</li>';
        }

        $rows = [
            ['Total paid', number_format((float) ($totals['total_paid'] ?? 0), 2)],
            ['Outstanding', number_format((float) ($totals['pending_due'] ?? 0), 2)],
            ['Mandatory outstanding', number_format((float) ($totals['pending_mandatory_due'] ?? 0), 2)],
            ['Project outstanding', number_format((float) ($totals['pending_project_due'] ?? 0), 2)],
            ['Overdue amount', number_format((float) ($totals['overdue_amount'] ?? 0), 2)],
            ['Voluntary collected', number_format((float) ($totals['voluntary_collected'] ?? 0), 2)],
        ];

        $metricsHtml = '';
        foreach ($rows as [$label, $value]) {
            $metricsHtml .= '<tr><td>' . htmlspecialchars($label) . '</td><td style="text-align:right">' . $value . '</td></tr>';
        }

        $healthScore = (int) ($health['score'] ?? 0);
        $healthLabel = htmlspecialchars((string) ($health['label'] ?? 'Calculating'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Family Financial Statement</title>
  <style>
    body { font-family: Georgia, "Times New Roman", serif; color: #111827; margin: 24px; }
    .report { max-width: 760px; margin: 0 auto; border: 1px solid #d1d5db; padding: 28px; }
    h1 { margin: 0 0 4px; font-size: 1.5rem; }
    h2 { margin: 20px 0 8px; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; color: #4b5563; }
    .meta { color: #6b7280; font-size: 0.92rem; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 4px; text-align: left; }
    ul { margin: 0; padding-left: 1.2rem; line-height: 1.5; }
    @media print { body { margin: 0; } .report { border: 0; } }
  </style>
</head>
<body>
  <div class="report">
    <h1>{$orgName}</h1>
    <div class="meta">Family Financial Statement · Printed {$printedAt}</div>
    <section>
      <h2>Family</h2>
      <p><strong>{$familyName}</strong> · {$familyCode}<br>Head of family: {$head}</p>
    </section>
    <section>
      <h2>Financial Health</h2>
      <p>{$healthLabel} · {$healthScore}/100</p>
    </section>
    <section>
      <h2>Summary</h2>
      <table><tbody>{$metricsHtml}</tbody></table>
    </section>
    <section><h2>Insights</h2><ul>{$insightsHtml}</ul></section>
    <section><h2>Recommended Actions</h2><ul>{$actionsHtml}</ul></section>
  </div>
</body>
</html>
HTML;
    }
}
