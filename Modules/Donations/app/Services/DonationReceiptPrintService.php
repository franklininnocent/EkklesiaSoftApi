<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Tenants\Models\Tenant;

class DonationReceiptPrintService
{
    public function __construct(private readonly DonationReceiptService $receiptService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPrintPayload(int $tenantId, DonationPayment $payment, DonationReceipt $receipt): array
    {
        $payload = $this->receiptService->buildReceiptPayload($tenantId, $payment, $receipt);
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        return array_merge($payload, [
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
        $receipt = $payload['receipt'] ?? [];
        $payment = $payload['payment'] ?? [];
        $payer = $payload['payer'] ?? [];
        $totals = $payload['totals'] ?? [];
        $organization = $payload['organization'] ?? [];
        $tax = $payload['tax_acknowledgement'] ?? [];
        $lineItems = $payload['line_items'] ?? [];

        $amount = number_format((float) ($totals['amount'] ?? 0), 2);
        $currency = htmlspecialchars((string) ($totals['currency'] ?? 'INR'));
        $receiptNumber = htmlspecialchars((string) ($receipt['receipt_number'] ?? ''));
        $issuedOn = htmlspecialchars((string) ($receipt['issued_on'] ?? ''));
        $payerName = htmlspecialchars((string) ($payer['name'] ?? 'Donor'));
        $method = htmlspecialchars((string) ($payment['method'] ?? ''));
        $paymentDate = htmlspecialchars((string) ($payment['payment_date'] ?? ''));
        $orgName = htmlspecialchars((string) ($organization['name'] ?? 'Parish'));

        $linesHtml = '';
        if (!empty($lineItems)) {
            foreach ($lineItems as $item) {
                $label = htmlspecialchars((string) ($item['description'] ?? 'Contribution'));
                $lineAmount = number_format((float) ($item['amount'] ?? 0), 2);
                $linesHtml .= "<tr><td>{$label}</td><td style=\"text-align:right\">{$lineAmount}</td></tr>";
            }
        } else {
            $linesHtml = "<tr><td>Contribution received</td><td style=\"text-align:right\">{$amount}</td></tr>";
        }

        $taxNote = '';
        if (!empty($tax['note'])) {
            $taxNote = '<p class="note">' . htmlspecialchars((string) $tax['note']) . '</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Receipt {$receiptNumber}</title>
  <style>
    body { font-family: Georgia, "Times New Roman", serif; color: #111827; margin: 24px; }
    .receipt { max-width: 640px; margin: 0 auto; border: 1px solid #d1d5db; padding: 24px; }
    h1 { margin: 0 0 4px; font-size: 1.35rem; }
    .meta { color: #4b5563; font-size: 0.92rem; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 4px; text-align: left; }
    .total { font-size: 1.1rem; font-weight: 700; margin-top: 12px; }
    .note { font-size: 0.85rem; color: #374151; margin-top: 16px; }
    @media print { body { margin: 0; } .receipt { border: 0; } }
  </style>
</head>
<body>
  <div class="receipt">
    <h1>{$orgName}</h1>
    <div class="meta">Official Contribution Receipt</div>
    <p><strong>Receipt #:</strong> {$receiptNumber}<br>
       <strong>Issued:</strong> {$issuedOn}<br>
       <strong>Payment date:</strong> {$paymentDate}<br>
       <strong>Method:</strong> {$method}</p>
    <p><strong>Received from:</strong> {$payerName}</p>
    <table>
      <thead><tr><th>Description</th><th style="text-align:right">Amount</th></tr></thead>
      <tbody>{$linesHtml}</tbody>
    </table>
    <div class="total">Total: {$amount} {$currency}</div>
    {$taxNote}
  </div>
</body>
</html>
HTML;
    }
}
