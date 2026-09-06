<?php

namespace Modules\Sacraments\Certificates;

/**
 * Server-side recipient name sizing (mirrors Angular text-fit/text-fit.ts).
 */
final class CertificateTextFit
{
    /**
     * @return array{fontSizeMm: float, status: string, overflow: bool}
     */
    public static function fitRecipientName(
        string $text,
        float $maxWidthMm,
        float $baseFontMm = CertificateLayoutTokens::SIZE_RECIPIENT,
        int $maxLines = CertificateLayoutTokens::RECIPIENT_MAX_LINES,
    ): array {
        $text = trim($text);
        if ($text === '') {
            return [
                'fontSizeMm' => $baseFontMm,
                'status' => 'OK',
                'overflow' => false,
            ];
        }

        $fontSize = $baseFontMm;
        $scaled = false;
        $minFont = $baseFontMm * CertificateLayoutTokens::TEXT_FIT_MIN_SCALE;
        $step = $baseFontMm * CertificateLayoutTokens::TEXT_FIT_SCALE_STEP;

        while ($fontSize >= $minFont - 0.001) {
            $lines = self::wrapLine($text, $maxWidthMm, $fontSize);
            $anyOverflow = false;
            foreach ($lines as $line) {
                if (self::measureWidthMm($line, $fontSize) > $maxWidthMm) {
                    $anyOverflow = true;
                    break;
                }
            }
            if (! $anyOverflow && count($lines) <= $maxLines) {
                return [
                    'fontSizeMm' => round($fontSize, 2),
                    'status' => $scaled ? 'SCALED' : 'OK',
                    'overflow' => false,
                ];
            }
            $scaled = true;
            $fontSize = round($fontSize - $step, 2);
        }

        return [
            'fontSizeMm' => round($minFont, 2),
            'status' => 'ERROR',
            'overflow' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private static function wrapLine(string $text, float $maxWidthMm, float $fontSizeMm): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (self::measureWidthMm($candidate, $fontSizeMm) <= $maxWidthMm || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private static function measureWidthMm(string $text, float $fontSizeMm): float
    {
        // Playfair Display display capitals run ~0.52em average width at certificate sizes.
        return mb_strlen($text) * $fontSizeMm * 0.52;
    }
}
