<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportDisplayValue;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Tenants\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class DonationReportXlsxExportService
{
    public function __construct(
        private readonly DonationReportFullPreviewLoader $previewLoader,
    ) {}

    public function write(int $tenantId, ReportFilter $filter, string $absolutePath): int
    {
        $preview = $this->previewLoader->load($tenantId, $filter);
        $definition = DonationReportCatalog::definition($filter->reportType);
        $tenant = Tenant::query()->find($tenantId);
        $orgName = (string) ($tenant?->name ?? 'Parish');
        $title = (string) ($definition['label'] ?? 'Report');
        $period = $this->periodLabel($filter, is_array($preview['meta'] ?? null) ? $preview['meta'] : []);
        $generatedAt = now()->toDateTimeString();

        $columns = is_array($preview['columns'] ?? null) ? $preview['columns'] : [];
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $footer = is_array($preview['footer'] ?? null) ? $preview['footer'] : [];
        $totals = is_array($preview['totals'] ?? null) ? $preview['totals'] : [];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $columnCount = max(1, count($columns));
        $lastColumn = $this->columnLetter($columnCount);

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', $orgName);
        $sheet->mergeCells("A2:{$lastColumn}2");

        $sheet->setCellValue('A3', $period);
        $sheet->mergeCells("A3:{$lastColumn}3");

        $sheet->setCellValue('A4', 'Generated '.$generatedAt);
        $sheet->mergeCells("A4:{$lastColumn}4");
        $sheet->getStyle('A2:A4')->getFont()->setItalic(true);

        $headerRow = 6;
        $colIndex = 1;
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $label = (string) ($column['label'] ?? '');
            $cell = $sheet->getCell([$colIndex, $headerRow]);
            $cell->setValue($label);
            $cell->getStyle()->getFont()->setBold(true);
            $cell->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF3F4F6');
            $colIndex++;
        }

        $dataRow = $headerRow + 1;
        $rowCount = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $colIndex = 1;
            foreach ($columns as $column) {
                if (! is_array($column)) {
                    continue;
                }
                $key = (string) ($column['key'] ?? '');
                $display = DonationReportDisplayValue::format($tenantId, $key, $row[$key] ?? '');
                $display = DonationReportDisplayValue::sanitizeSpreadsheetCell($display);
                $sheet->setCellValueExplicit([$colIndex, $dataRow], $display, DataType::TYPE_STRING);
                $colIndex++;
            }
            $dataRow++;
            $rowCount++;
        }

        if ($footer !== [] && $columns !== []) {
            $colIndex = 1;
            foreach ($columns as $index => $column) {
                if (! is_array($column)) {
                    $colIndex++;

                    continue;
                }
                $key = (string) ($column['key'] ?? '');
                $value = $footer[$key] ?? ($index === 0 ? 'Total' : '');
                $display = DonationReportDisplayValue::format($tenantId, $key, $value);
                $display = DonationReportDisplayValue::sanitizeSpreadsheetCell($display);
                $cell = $sheet->getCell([$colIndex, $dataRow]);
                $cell->setValueExplicit($display, DataType::TYPE_STRING);
                $cell->getStyle()->getFont()->setBold(true);
                $colIndex++;
            }
            $dataRow += 2;
        }

        if ($totals !== []) {
            $sheet->setCellValue([1, $dataRow], 'Summary totals');
            $sheet->getStyle([1, $dataRow])->getFont()->setBold(true);
            $dataRow++;
            foreach ($totals as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $label = str_replace('_', ' ', (string) $key);
                if (is_numeric($value) && DonationReportDisplayValue::isMoneyKey((string) $key)) {
                    $display = DonationReportDisplayValue::format($tenantId, (string) $key, $value);
                } else {
                    $display = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
                }
                $sheet->setCellValue([1, $dataRow], $label);
                $sheet->setCellValueExplicit([2, $dataRow], DonationReportDisplayValue::sanitizeSpreadsheetCell($display), DataType::TYPE_STRING);
                $dataRow++;
            }
        }

        foreach (range(1, $columnCount) as $index) {
            $sheet->getColumnDimension($this->columnLetter($index))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($absolutePath);
        $spreadsheet->disconnectWorksheets();

        return $rowCount;
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

    private function columnLetter(int $columnIndex): string
    {
        $letter = '';
        while ($columnIndex > 0) {
            $columnIndex--;
            $letter = chr(65 + ($columnIndex % 26)).$letter;
            $columnIndex = intdiv($columnIndex, 26);
        }

        return $letter;
    }
}
