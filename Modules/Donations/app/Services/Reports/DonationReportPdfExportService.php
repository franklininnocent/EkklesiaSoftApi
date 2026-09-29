<?php

namespace Modules\Donations\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Modules\Donations\Support\Reports\ReportFilter;

final class DonationReportPdfExportService
{
    public function __construct(
        private readonly DonationReportFullPreviewLoader $previewLoader,
        private readonly DonationOperationalReportPrintService $printService,
    ) {}

    public function write(int $tenantId, ReportFilter $filter, string $absolutePath): int
    {
        $preview = $this->previewLoader->load($tenantId, $filter);
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $rowCount = count($rows);

        $html = $this->printService->renderDocumentHtml($tenantId, $filter, $preview);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $written = file_put_contents($absolutePath, $dompdf->output());
        if ($written === false) {
            throw new \RuntimeException('Could not write PDF export file.');
        }

        return $rowCount;
    }
}
