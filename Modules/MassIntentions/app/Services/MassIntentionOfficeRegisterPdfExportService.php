<?php

namespace Modules\MassIntentions\Services;

use App\Support\UserFacingDate;
use App\Support\UserFacingDate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;

final class MassIntentionOfficeRegisterPdfExportService
{
    public function __construct(
        private readonly MassIntentionRequestService $requests,
    ) {
    }
    /**
     * @param  Collection<int, MassIntentionRequest>  $rows
     */
    public function renderPdf(Collection $rows, string $filterSummaryLine): string
    {
        $html = $this->renderHtml($rows, $filterSummaryLine);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  Collection<int, MassIntentionRequest>  $rows
     */
    private function renderHtml(Collection $rows, string $filterSummaryLine): string
    {
        $count = $rows->count();
        $bodyRows = $rows
            ->map(fn (MassIntentionRequest $row) => $this->tableRow($row))
            ->implode('');

        $summary = $this->escape($filterSummaryLine);
        $recordLabel = $count === 1 ? 'record' : 'records';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Mass intentions register</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; margin: 12px; }
    h1 { font-size: 14px; margin: 0 0 4px; }
    p.meta { margin: 0 0 12px; color: #64748b; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #f8fafc; font-size: 10px; }
    td.desc { max-width: 200px; word-wrap: break-word; }
  </style>
</head>
<body>
  <h1>Mass intentions — office register</h1>
  <p class="meta">{$summary} · {$count} {$recordLabel}</p>
  <table>
    <thead>
      <tr>
        <th>Mass</th>
        <th>For</th>
        <th>Intention</th>
        <th>Description</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      {$bodyRows}
    </tbody>
  </table>
</body>
</html>
HTML;
    }

    private function tableRow(MassIntentionRequest $row): string
    {
        $day = $this->escape($this->requests->officeRegisterMassLabel($row));
        $name = $this->beneficiaryForCell($row);
        $type = $this->escape($this->intentionTypeLabel($row));
        $description = $this->escape($this->intentionDescription($row) ?? '—');
        $status = $this->escape($this->statusLabel((string) $row->status));

        return "<tr>
        <td>{$day}</td>
        <td>{$name}</td>
        <td>{$type}</td>
        <td class=\"desc\">{$description}</td>
        <td>{$status}</td>
      </tr>";
    }

    private function intentionTypeLabel(MassIntentionRequest $row): string
    {
        if ($row->mass_intention_category_id) {
            $text = trim((string) ($row->intention_text ?? ''));
            return $text !== '' ? $text : '—';
        }

        return '—';
    }

    private function intentionDescription(MassIntentionRequest $row): ?string
    {
        $description = trim((string) ($row->intention_description ?? ''));
        if ($description !== '') {
            return $description;
        }

        if (! $row->mass_intention_category_id) {
            $legacy = trim((string) ($row->intention_text ?? ''));
            return $legacy !== '' ? $legacy : null;
        }

        return null;
    }

    private function statusLabel(string $status): string
    {
        if ($status === MassIntentionStatus::OPEN) {
            return 'Open';
        }
        if ($status === MassIntentionStatus::CLOSED) {
            return 'Closed';
        }

        return $status;
    }

    private function beneficiaryForCell(MassIntentionRequest $row): string
    {
        $name = $this->escape((string) ($row->beneficiary_name ?? ''));
        $meta = $this->beneficiaryIdentificationLine($row);
        if ($meta === '') {
            return $name;
        }

        return $name.'<br /><span style="color:#64748b;font-size:9px;">'.$this->escape($meta).'</span>';
    }

    private function beneficiaryIdentificationLine(MassIntentionRequest $row): string
    {
        $bccName = trim((string) ($row->beneficiary_bcc_name ?? ''));
        if ($bccName !== '') {
            return 'BCC · '.$bccName;
        }

        $place = trim((string) ($row->beneficiary_place ?? ''));
        if ($place !== '') {
            return 'Place · '.$place;
        }

        return '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function buildFilterSummaryLineFromRequest(Request $request): string
    {
        $parts = [];
        $status = (string) $request->input('status', '');
        if ($status === 'open') {
            $parts[] = 'Status: Open';
        } elseif ($status === 'closed') {
            $parts[] = 'Status: Closed';
        }
        $requestedDate = trim((string) $request->input('requested_date', ''));
        if ($requestedDate !== '') {
            $parts[] = 'Scheduled day: '.$this->formatIsoDateLabel($requestedDate);
        }
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $parts[] = 'Search: '.$search;
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All intentions (no filters)';
    }

    private function formatIsoDateLabel(string $isoDate): string
    {
        try {
            $label = UserFacingDate::formatDate($isoDate);

            return $label !== '' ? $label : $isoDate;
        } catch (\Throwable) {
            return $isoDate;
        }
    }
}
