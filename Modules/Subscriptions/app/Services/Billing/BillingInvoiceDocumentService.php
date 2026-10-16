<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\UserFacingDate;
use Dompdf\Dompdf;
use Modules\Subscriptions\Models\BillingCreditNote;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingInvoiceLine;
use Symfony\Component\HttpFoundation\Response;

final class BillingInvoiceDocumentService
{
    public function streamHtml(BillingInvoice $invoice): Response
    {
        return response($this->buildHtml($invoice), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="invoice-'.$invoice->invoice_number.'.html"',
        ]);
    }

    public function streamPdf(BillingInvoice $invoice): Response
    {
        return $this->streamPdfHtml($this->buildHtml($invoice), 'invoice-'.$invoice->invoice_number);
    }

    public function streamCreditNotePdf(BillingCreditNote $note): Response
    {
        $invoice = $note->invoice;
        $issued = $note->issued_at ? UserFacingDate::formatDateTime($note->issued_at) : '—';
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Credit note {$note->credit_note_number}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}</style>
</head>
<body>
<h1>Credit note {$note->credit_note_number}</h1>
<p>Issued: {$issued}</p>
<p>Invoice: {$invoice?->invoice_number}</p>
<p><strong>Amount: {$note->currency_code} {$note->amount}</strong></p>
</body>
</html>
HTML;

        return $this->streamPdfHtml($html, 'credit-note-'.$note->credit_note_number);
    }

    private function streamPdfHtml(string $html, string $basename): Response
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$basename.'.pdf"',
        ]);
    }

    private function buildHtml(BillingInvoice $invoice): string
    {
        $lines = BillingInvoiceLine::query()->where('billing_invoice_id', $invoice->id)->orderBy('id')->get();
        $issued = $invoice->issued_at ? UserFacingDate::formatDateTime($invoice->issued_at) : '—';

        $rows = '';
        foreach ($lines as $line) {
            $rows .= sprintf(
                '<tr><td>%s</td><td style="text-align:right">%s %s</td></tr>',
                e((string) $line->description),
                e((string) $invoice->currency_code),
                e((string) $line->line_total),
            );
        }
        if ($rows === '') {
            $rows = sprintf(
                '<tr><td>Subscription</td><td style="text-align:right">%s %s</td></tr>',
                e((string) $invoice->currency_code),
                e((string) $invoice->gross_amount),
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Invoice {$invoice->invoice_number}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:8px}</style>
</head>
<body>
<h1>Invoice {$invoice->invoice_number}</h1>
<p>Issued: {$issued}</p>
<p>Status: {$invoice->status}</p>
<table>
<thead><tr><th>Description</th><th>Amount</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
<p><strong>Total: {$invoice->currency_code} {$invoice->gross_amount}</strong></p>
<p>Amount paid: {$invoice->currency_code} {$invoice->amount_paid}</p>
</body>
</html>
HTML;
    }
}
