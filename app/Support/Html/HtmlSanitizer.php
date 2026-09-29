<?php

namespace App\Support\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Allowlist HTML sanitizer for TipTap rich-text fields.
 *
 * Only formatting elements used by the shared editor are permitted.
 * Scripts, event handlers, styles (except text-align), embeds, and unsafe URLs are stripped.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * Sanitize rich HTML for storage.
     * Returns null when the result is empty / meaningless markup.
     */
    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $trimmed = trim($html);
        if ($trimmed === '') {
            return null;
        }

        $clean = trim($this->purifier()->purify($trimmed));

        if ($clean === '' || $this->isEmptyMarkup($clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function sanitizeFields(array $input, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                $input[$field] = null;

                continue;
            }

            $input[$field] = $this->sanitize($value);
        }

        return $input;
    }

    private function isEmptyMarkup(string $html): bool
    {
        // A lone horizontal rule is intentional structural content.
        if (preg_match('/<hr\b/i', $html)) {
            return false;
        }

        $stripped = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $stripped = preg_replace('/\x{00A0}|\s+/u', '', $stripped) ?? '';

        return $stripped === '';
    }

    private function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', implode(',', [
            'p[style]',
            'br',
            'h1[style]',
            'h2[style]',
            'h3[style]',
            'strong',
            'b',
            'em',
            'i',
            'u',
            's',
            'strike',
            'del',
            'ul',
            'ol',
            'li',
            'blockquote[style]',
            'hr',
            'a[href|title|rel|target]',
        ]));
        $config->set('CSS.AllowedProperties', 'text-align');
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', false);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);
        $config->set('AutoFormat.RemoveEmpty', true);

        self::$purifier = new HTMLPurifier($config);

        return self::$purifier;
    }
}
