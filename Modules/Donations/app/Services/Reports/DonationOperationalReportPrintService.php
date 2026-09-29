<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportDisplayValue;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantPrivateStorage;

final class DonationOperationalReportPrintService
{
    public function __construct(
        private readonly DonationModuleReportService $moduleReportService,
    ) {}

    public function renderHtml(int $tenantId, ReportFilter $filter): string
    {
        $definition = DonationReportCatalog::definition($filter->reportType);
        if (! ($definition['print'] ?? false)) {
            throw new \InvalidArgumentException('This report cannot be printed.');
        }

        $preview = $this->moduleReportService->preview($tenantId, ReportFilter::fromValidated(
            $filter->reportType,
            array_merge($filter->raw, ['page' => 1, 'per_page' => 500]),
        ));

        return $this->renderDocumentHtml($tenantId, $filter, $preview);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function renderDocumentHtml(int $tenantId, ReportFilter $filter, array $preview): string
    {
        $definition = DonationReportCatalog::definition($filter->reportType);
        $tenant = Tenant::query()->find($tenantId);
        $orgName = htmlspecialchars((string) ($tenant?->name ?? 'Parish'));
        $title = htmlspecialchars((string) ($definition['label'] ?? 'Report'));
        $printedAt = htmlspecialchars(now()->toDateTimeString());
        $meta = is_array($preview['meta'] ?? null) ? $preview['meta'] : [];
        $period = htmlspecialchars($this->periodLabel($filter, $meta));
        $logoHtml = $this->logoMarkup($tenant);

        $headers = is_array($preview['columns'] ?? null) ? $preview['columns'] : [];
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $totals = is_array($preview['totals'] ?? null) ? $preview['totals'] : [];

        $headHtml = '';
        foreach ($headers as $col) {
            if (! is_array($col)) {
                continue;
            }
            $headHtml .= '<th>'.htmlspecialchars((string) ($col['label'] ?? '')).'</th>';
        }

        $bodyHtml = '';
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bodyHtml .= '<tr>';
            foreach ($headers as $col) {
                if (! is_array($col)) {
                    continue;
                }
                $key = (string) ($col['key'] ?? '');
                $display = htmlspecialchars(DonationReportDisplayValue::format($tenantId, $key, $row[$key] ?? ''));
                $bodyHtml .= '<td>'.$display.'</td>';
            }
            $bodyHtml .= '</tr>';
        }

        if ($bodyHtml === '') {
            $colspan = max(1, count($headers));
            $bodyHtml = '<tr><td colspan="'.$colspan.'">No records match the selected filters.</td></tr>';
        }

        $footer = is_array($preview['footer'] ?? null) ? $preview['footer'] : [];
        $footHtml = '';
        if ($footer !== [] && $headers !== []) {
            $footHtml .= '<tr>';
            foreach ($headers as $index => $col) {
                if (! is_array($col)) {
                    continue;
                }
                $key = (string) ($col['key'] ?? '');
                $value = $footer[$key] ?? ($index === 0 ? 'Total' : '');
                $display = htmlspecialchars(DonationReportDisplayValue::format($tenantId, $key, $value));
                $footHtml .= '<td><strong>'.$display.'</strong></td>';
            }
            $footHtml .= '</tr>';
        }

        $totalsHtml = '';
        foreach ($totals as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $display = htmlspecialchars(DonationReportDisplayValue::format($tenantId, (string) $key, $value));
            $totalsHtml .= '<tr><td>'.htmlspecialchars(str_replace('_', ' ', (string) $key)).'</td><td style="text-align:right">'.$display.'</td></tr>';
        }

        $amountBasis = isset($meta['amount_basis']) ? htmlspecialchars((string) $meta['amount_basis']) : '';
        $metaLine = $amountBasis !== '' ? ' · Amount basis: '.$amountBasis : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$title} · {$orgName}</title>
  <style>
    body { font-family: DejaVu Sans, system-ui, sans-serif; color: #111827; margin: 24px; }
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
    .brand img { max-height: 48px; max-width: 120px; object-fit: contain; }
    h1 { margin: 0 0 4px; font-size: 1.35rem; }
    .meta { color: #6b7280; font-size: 0.9rem; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.85rem; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; text-align: left; }
    th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #4b5563; }
    tfoot td { border-top: 2px solid #111827; background: #f9fafb; }
    @media print { body { margin: 0; } }
  </style>
</head>
<body>
  <div class="brand">{$logoHtml}<div><h1>{$title}</h1></div></div>
  <p class="meta">{$orgName} · {$period} · Generated {$printedAt}{$metaLine}</p>
  <table>
    <thead><tr>{$headHtml}</tr></thead>
    <tbody>{$bodyHtml}</tbody>
    <tfoot>{$footHtml}</tfoot>
  </table>
  <h2 style="margin-top:20px;font-size:1rem;">Totals</h2>
  <table>{$totalsHtml}</table>
</body>
</html>
HTML;
    }

    private function logoMarkup(?Tenant $tenant): string
    {
        if ($tenant === null || ! is_string($tenant->logo_url) || $tenant->logo_url === '') {
            return '';
        }

        if (! TenantPrivateStorage::exists($tenant->logo_url)) {
            return '';
        }

        $binary = TenantPrivateStorage::get($tenant->logo_url);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary) ?: 'image/png';
        $dataUri = 'data:'.$mime.';base64,'.base64_encode($binary);

        return '<img src="'.htmlspecialchars($dataUri).'" alt="" />';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function periodLabel(ReportFilter $filter, array $meta): string
    {
        if ($filter->get('as_of_date')) {
            return 'As of '.$filter->get('as_of_date');
        }
        if ($filter->get('date_from') && $filter->get('date_to')) {
            return $filter->get('date_from').' to '.$filter->get('date_to');
        }
        if (isset($meta['as_of'])) {
            return 'As of '.$meta['as_of'];
        }

        return 'Selected period';
    }
}
