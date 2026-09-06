<?php

namespace Modules\Sacraments\Certificates;

/**
 * Canonical certificate layout + typography tokens (mm).
 * Keep in sync with EkklesiaSoftUi/src/styles/certificate-tokens.scss
 */
final class CertificateLayoutTokens
{
    public const SAFE_PADDING_TOP = 11;

    public const SAFE_PADDING_X = 16;

    public const SAFE_PADDING_BOTTOM = 9;

    public const GAP_SECTION = 4;

    public const GAP_AFTER_RECIPIENT = 3.5;

    public const GAP_AFTER_DETAILS = 3;

    public const SPACER_MIN = 4;

    public const SPACER_MAX = 18;

    public const SIZE_TITLE = 12;

    public const SIZE_KICKER = 3.4;

    public const SIZE_CHURCH = 4.8;

    public const SIZE_RECIPIENT = 10;

    public const SIZE_RECIPIENT_LABEL = 3;

    public const SIZE_LABEL = 2.8;

    public const SIZE_DETAIL = 4.6;

    public const SIZE_META = 3.2;

    public const SIZE_META_LABEL = 2.6;

    public const SIZE_REGISTRY = 3.4;

    public const SIZE_SIGNATURE_LINE = 3.6;

    public const SIZE_SIGNATURE_LABEL = 2.6;

    public const SIZE_FOOTER = 3;

    public const RECIPIENT_MAX_LINES = 2;

    public const TEXT_FIT_MIN_SCALE = 0.72;

    public const TEXT_FIT_SCALE_STEP = 0.04;

    /**
     * @return array<string, string>
     */
    public static function cssVariables(): array
    {
        return [
            '--cert-safe-pt' => self::SAFE_PADDING_TOP.'mm',
            '--cert-safe-px' => self::SAFE_PADDING_X.'mm',
            '--cert-safe-pb' => self::SAFE_PADDING_BOTTOM.'mm',
            '--cert-gap-section' => self::GAP_SECTION.'mm',
            '--cert-gap-after-recipient' => self::GAP_AFTER_RECIPIENT.'mm',
            '--cert-gap-after-details' => self::GAP_AFTER_DETAILS.'mm',
            '--cert-spacer-min' => self::SPACER_MIN.'mm',
            '--cert-spacer-max' => self::SPACER_MAX.'mm',
            '--cert-size-title' => self::SIZE_TITLE.'mm',
            '--cert-size-kicker' => self::SIZE_KICKER.'mm',
            '--cert-size-church' => self::SIZE_CHURCH.'mm',
            '--cert-size-recipient' => self::SIZE_RECIPIENT.'mm',
            '--cert-size-recipient-label' => self::SIZE_RECIPIENT_LABEL.'mm',
            '--cert-size-label' => self::SIZE_LABEL.'mm',
            '--cert-size-detail' => self::SIZE_DETAIL.'mm',
            '--cert-size-meta' => self::SIZE_META.'mm',
            '--cert-size-meta-label' => self::SIZE_META_LABEL.'mm',
            '--cert-size-registry' => self::SIZE_REGISTRY.'mm',
            '--cert-size-signature-line' => self::SIZE_SIGNATURE_LINE.'mm',
            '--cert-size-signature-label' => self::SIZE_SIGNATURE_LABEL.'mm',
            '--cert-size-footer' => self::SIZE_FOOTER.'mm',
            '--cert-size-field-value' => '3.8mm',
            '--cert-line-height-body' => '1.35',
        ];
    }

    /**
     * Content width inside safe area for A4 landscape (mm).
     */
    public static function contentWidthMm(string $paper): float
    {
        $pageWidth = strtoupper($paper) === 'LETTER' ? 279.4 : 297.0;

        return $pageWidth - (self::SAFE_PADDING_X * 2);
    }
}
