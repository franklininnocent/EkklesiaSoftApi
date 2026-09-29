<?php

namespace App\Support\Email;

/**
 * Read-only accessor for email design tokens (config/email.php).
 */
final class EmailTheme
{
    public static function productName(): string
    {
        return (string) config('email.product_name', 'EkklesiaSoft');
    }

    public static function logoUrl(): ?string
    {
        $url = config('email.logo_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function supportAddress(): ?string
    {
        $address = config('email.support_address');

        return is_string($address) && $address !== '' ? $address : null;
    }

    public static function containerWidth(): int
    {
        return (int) config('email.container_width', 640);
    }

    public static function color(string $key, string $fallback = '#0f172a'): string
    {
        return (string) config("email.colors.{$key}", $fallback);
    }

    public static function type(string $key, string $fallback = '16px'): string
    {
        return (string) config("email.typography.{$key}", $fallback);
    }

    public static function space(string $key, string $fallback = '16px'): string
    {
        return (string) config("email.spacing.{$key}", $fallback);
    }

    public static function radius(string $key, string $fallback = '8px'): string
    {
        return (string) config("email.radius.{$key}", $fallback);
    }

    public static function button(string $key, string $fallback = '44px'): string
    {
        return (string) config("email.button.{$key}", $fallback);
    }

    /**
     * @return array<string, string>
     */
    public static function inlineBaseStyles(): array
    {
        return [
            'fontFamily' => self::type('font_family'),
            'bodySize' => self::type('body_size'),
            'bodyLineHeight' => self::type('body_line_height'),
            'text' => self::color('text'),
            'textSecondary' => self::color('text_secondary'),
            'muted' => self::color('muted'),
            'background' => self::color('background'),
            'surface' => self::color('surface'),
            'border' => self::color('border'),
            'primary' => self::color('primary'),
            'primaryText' => self::color('primary_text'),
            'link' => self::color('link'),
            'headingSize' => self::type('heading_size'),
            'headingLineHeight' => self::type('heading_line_height'),
            'sectionSize' => self::type('section_size'),
            'secondarySize' => self::type('secondary_size'),
            'labelSize' => self::type('label_size'),
            'footerSize' => self::type('footer_size'),
            'buttonSize' => self::type('button_size'),
            'pagePad' => self::space('page_pad'),
            'sectionGap' => self::space('section_gap'),
            'cardPad' => self::space('card_pad'),
            'buttonPadY' => self::space('button_pad_y'),
            'buttonPadX' => self::space('button_pad_x'),
            'cardRadius' => self::radius('card'),
            'buttonRadius' => self::radius('button'),
            'buttonMinHeight' => self::button('min_height'),
            'containerWidth' => (string) self::containerWidth(),
            'headerBar' => self::color('header_bar'),
            'success' => self::color('success'),
            'successSoft' => self::color('success_soft'),
            'warning' => self::color('warning'),
            'warningSoft' => self::color('warning_soft'),
            'danger' => self::color('danger'),
            'dangerSoft' => self::color('danger_soft'),
            'info' => self::color('info'),
            'infoSoft' => self::color('info_soft'),
            'divider' => self::color('divider'),
        ];
    }
}
