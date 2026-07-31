<?php

namespace Modules\Donations\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Modules\Donations\Support\MoneyMath;

class PaymentReceiptOcrService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(?UploadedFile $image = null, ?string $extractedText = null): array
    {
        $text = trim((string) $extractedText);

        if ($text === '' && $image) {
            $text = $this->extractTextFromImage($image);
        }

        if ($text === '') {
            return [
                'available' => false,
                'message' => 'No readable text was found. Enter the amount manually or retry with a clearer photo.',
                'confidence' => 0,
            ];
        }

        $parsed = $this->parseReceiptText($text);

        return [
            'available' => $parsed['suggested_amount'] !== null,
            'confidence' => $parsed['confidence'],
            'extracted_text' => mb_substr($text, 0, 1200),
            'suggested_amount' => $parsed['suggested_amount'],
            'suggested_payment_date' => $parsed['suggested_payment_date'],
            'suggested_payer_name' => $parsed['suggested_payer_name'],
            'suggested_method' => $parsed['suggested_method'],
            'message' => $parsed['suggested_amount'] !== null
                ? 'Receipt text parsed successfully. Review the suggested amount before saving.'
                : 'Text detected, but no amount could be parsed confidently.',
        ];
    }

    private function extractTextFromImage(UploadedFile $image): string
    {
        $binary = Process::run(['which', 'tesseract']);
        if (!$binary->successful()) {
            return '';
        }

        $path = $image->getRealPath();
        if (!$path) {
            return '';
        }

        $result = Process::run(['tesseract', $path, 'stdout', '-l', 'eng']);

        return trim($result->output());
    }

    /**
     * @return array{
     *   suggested_amount: float|null,
     *   suggested_payment_date: string|null,
     *   suggested_payer_name: string|null,
     *   suggested_method: string|null,
     *   confidence: int
     * }
     */
    private function parseReceiptText(string $text): array
    {
        $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;
        $amount = null;
        $confidence = 35;

        $patterns = [
            '/(?:total|amount|paid|rs\.?|inr|₹)\s*[:\-]?\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?)/i',
            '/₹\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?)/',
            '/\b([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?)\b/',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $candidate = (float) str_replace(',', '', $matches[1]);
                if ($candidate > 0 && $candidate <= 10000000) {
                    $amount = MoneyMath::round($candidate);
                    $confidence = 90 - ($index * 15);
                    break;
                }
            }
        }

        $paymentDate = null;
        if (preg_match('/\b(20\d{2}[-\/\.](0[1-9]|1[0-2])[-\/\.](0[1-9]|[12]\d|3[01]))\b/', $normalized, $dateMatch)) {
            $paymentDate = str_replace(['/', '.'], '-', $dateMatch[1]);
            $confidence += 5;
        } elseif (preg_match('/\b((0[1-9]|[12]\d|3[01])[-\/\.](0[1-9]|1[0-2])[-\/\.](20\d{2}))\b/', $normalized, $dateMatch)) {
            $parts = preg_split('/[-\/\.]/', $dateMatch[1]) ?: [];
            if (count($parts) === 3) {
                $paymentDate = sprintf('%s-%s-%s', $parts[2], $parts[1], $parts[0]);
                $confidence += 5;
            }
        }

        $method = null;
        if (preg_match('/\b(upi|cash|cheque|check|bank|neft|imps|rtgs)\b/i', $normalized, $methodMatch)) {
            $method = strtolower($methodMatch[1]);
            if (in_array($method, ['check'], true)) {
                $method = 'cheque';
            }
            if (in_array($method, ['bank', 'neft', 'imps', 'rtgs'], true)) {
                $method = 'bank_transfer';
            }
            $confidence += 5;
        }

        $payerName = null;
        if (preg_match('/(?:from|payer|paid by|name)\s*[:\-]\s*([A-Za-z][A-Za-z\s\.\']{2,40})/i', $normalized, $nameMatch)) {
            $payerName = trim($nameMatch[1]);
            $confidence += 5;
        }

        return [
            'suggested_amount' => $amount,
            'suggested_payment_date' => $paymentDate,
            'suggested_payer_name' => $payerName,
            'suggested_method' => $method,
            'confidence' => min(98, $confidence),
        ];
    }
}
