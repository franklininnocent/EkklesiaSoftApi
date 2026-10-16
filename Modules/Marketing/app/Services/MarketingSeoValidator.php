<?php

namespace Modules\Marketing\Services;

class MarketingSeoValidator
{
    public const PRODUCTION_CANONICAL_ORIGIN = 'https://ekklesianexus.com';

    /** Meta description max (homepage product copy is 325 characters). */
    public const SEO_DESCRIPTION_MAX_LENGTH = 400;

    /** @var array<string, string> page id => path (required when seo is present) */
    public const PAGE_REGISTRY = [
        'home' => '/',
        'privacy' => '/privacy',
        'terms' => '/terms',
    ];

    /** @var array<string, string> optional CMS overrides for static marketing routes */
    public const OPTIONAL_PAGE_REGISTRY = [
        'features' => '/features',
        'about' => '/about',
        'contact' => '/contact',
        'solutionsCatholicParishes' => '/solutions/catholic-parishes',
    ];

    /** @var list<string> page ids that must never be indexed in Wave 1 */
    public const NOINDEX_LOCKED_PAGE_IDS = ['privacy', 'terms'];

    /** @var list<string> */
    private const SEO_PROHIBITED_PATTERNS = [
        '/\battendance\b/i',
        '/\btestimonial\b/i',
        '/\b5 stars?\b/i',
        '/\breview\b/i',
        '/\bAggregateRating\b/i',
        '/\blocalhost\b/i',
        '/\b127\.0\.0\.1\b/i',
        '/\bAI-powered\b/i',
        '/\bmobile app\b/i',
        '/\bvolunteer management\b/i',
    ];

    /** @var list<string> */
    private const ALLOWED_OG_PATH_PREFIXES = ['/images/', '/logos/', '/favicon/'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateAndNormalizeSeo(array &$payload, bool $isPublish): void
    {
        if (! array_key_exists('seo', $payload) || $payload['seo'] === null) {
            return;
        }

        if (! is_array($payload['seo'])) {
            throw new \InvalidArgumentException('Marketing content payload.seo must be an object.');
        }

        $seo = $payload['seo'];
        $this->rejectUnknownKeys($seo, [
            'schemaVersion',
            'canonicalOrigin',
            'defaultLocale',
            'ogLocale',
            'defaultOgImage',
            'organizationName',
            'organizationDescription',
            'verification',
            'analytics',
            'robots',
            'pages',
            'publishedAt',
        ], 'seo');

        $origin = $this->validateCanonicalOrigin($seo['canonicalOrigin'] ?? null);
        $seo['canonicalOrigin'] = $origin;

        $defaultOg = $this->validateOgImagePath($seo['defaultOgImage'] ?? '/images/og/og-preview.png');
        $seo['defaultOgImage'] = $defaultOg;

        if (array_key_exists('organizationName', $seo) && $seo['organizationName'] !== null) {
            $seo['organizationName'] = $this->validatePlainTextField((string) $seo['organizationName'], 'organizationName', 120);
            $this->scanProhibitedSeoText($seo['organizationName'], 'seo.organizationName');
        }

        if (array_key_exists('organizationDescription', $seo) && $seo['organizationDescription'] !== null) {
            $seo['organizationDescription'] = $this->validatePlainTextField(
                (string) $seo['organizationDescription'],
                'organizationDescription',
                500
            );
            $this->scanProhibitedSeoText($seo['organizationDescription'], 'seo.organizationDescription');
        }

        if (isset($seo['verification']) && $seo['verification'] !== null) {
            if (! is_array($seo['verification'])) {
                throw new \InvalidArgumentException('seo.verification must be an object.');
            }
            $this->rejectUnknownKeys($seo['verification'], ['google', 'bing'], 'seo.verification');
            if (isset($seo['verification']['google'])) {
                $this->validateGoogleVerificationToken((string) $seo['verification']['google']);
            }
            if (isset($seo['verification']['bing'])) {
                $this->validateBingVerificationToken((string) $seo['verification']['bing']);
            }
        }

        if (isset($seo['analytics']) && $seo['analytics'] !== null) {
            if (! is_array($seo['analytics'])) {
                throw new \InvalidArgumentException('seo.analytics must be an object.');
            }
            $this->rejectUnknownKeys($seo['analytics'], ['ga4MeasurementId'], 'seo.analytics');
            if (isset($seo['analytics']['ga4MeasurementId'])) {
                $this->validateGa4Id((string) $seo['analytics']['ga4MeasurementId']);
            }
        }

        if (isset($seo['robots']) && $seo['robots'] !== null) {
            if (! is_array($seo['robots'])) {
                throw new \InvalidArgumentException('seo.robots must be an object.');
            }
            $this->rejectUnknownKeys($seo['robots'], ['allowRoot'], 'seo.robots');
            if (array_key_exists('allowRoot', $seo['robots']) && $seo['robots']['allowRoot'] !== true) {
                throw new \InvalidArgumentException('Disallowing the entire marketing site is not permitted.');
            }
        }

        if (! isset($seo['pages']) || ! is_array($seo['pages'])) {
            throw new \InvalidArgumentException('seo.pages must be an object when seo is present.');
        }

        foreach (array_keys(self::PAGE_REGISTRY) as $requiredId) {
            if (! isset($seo['pages'][$requiredId]) || ! is_array($seo['pages'][$requiredId])) {
                throw new \InvalidArgumentException("seo.pages.{$requiredId} is required when seo is present.");
            }
        }

        $allowedPageIds = self::PAGE_REGISTRY + self::OPTIONAL_PAGE_REGISTRY;

        $seenPaths = [];
        foreach ($seo['pages'] as $pageId => $page) {
            if (! is_string($pageId) || ! array_key_exists($pageId, $allowedPageIds)) {
                throw new \InvalidArgumentException("Unknown or unsupported SEO page id: {$pageId}");
            }
            if (! is_array($page)) {
                throw new \InvalidArgumentException("seo.pages.{$pageId} must be an object.");
            }
            $expectedPath = $allowedPageIds[$pageId];
            $this->rejectUnknownKeys($page, [
                'id',
                'name',
                'path',
                'seoTitle',
                'seoDescription',
                'ogTitle',
                'ogDescription',
                'ogImage',
                'robotsIndex',
                'robotsFollow',
            ], "seo.pages.{$pageId}");

            $path = (string) ($page['path'] ?? '');
            if ($path !== $expectedPath) {
                throw new \InvalidArgumentException("seo.pages.{$pageId}.path must be {$expectedPath}.");
            }
            if (isset($seenPaths[$path])) {
                throw new \InvalidArgumentException("Duplicate SEO path mapping: {$path}");
            }
            $seenPaths[$path] = true;

            $page['id'] = $pageId;
            $title = $this->validatePlainTextField((string) ($page['seoTitle'] ?? ''), 'seoTitle', 70);
            $description = $this->validatePlainTextField(
                (string) ($page['seoDescription'] ?? ''),
                'seoDescription',
                self::SEO_DESCRIPTION_MAX_LENGTH,
            );
            if ($title === '' || $description === '') {
                throw new \InvalidArgumentException("seo.pages.{$pageId} requires non-empty seoTitle and seoDescription.");
            }
            $this->scanProhibitedSeoText($title, "seo.pages.{$pageId}.seoTitle");
            $this->scanProhibitedSeoText($description, "seo.pages.{$pageId}.seoDescription");

            $robotsIndex = (bool) ($page['robotsIndex'] ?? false);
            $robotsFollow = (bool) ($page['robotsFollow'] ?? true);
            if (in_array($pageId, self::NOINDEX_LOCKED_PAGE_IDS, true)) {
                if ($robotsIndex) {
                    throw new \InvalidArgumentException("seo.pages.{$pageId} cannot be set to index until legal review approves.");
                }
                $robotsIndex = false;
                $robotsFollow = false;
            }

            if (isset($page['ogTitle'])) {
                $this->validatePlainTextField((string) $page['ogTitle'], 'ogTitle', 70);
            }
            if (isset($page['ogDescription'])) {
                $this->validatePlainTextField((string) $page['ogDescription'], 'ogDescription', 180);
            }
            if (isset($page['ogImage'])) {
                $page['ogImage'] = $this->validateOgImagePath((string) $page['ogImage']);
            }

            $page['robotsIndex'] = $robotsIndex;
            $page['robotsFollow'] = $robotsFollow;
            $seo['pages'][$pageId] = $page;
        }

        if ($isPublish) {
            $this->assertUniqueSeoTitles($seo['pages']);
            $seo['publishedAt'] = now()->toIso8601String();
        }

        $payload['seo'] = $seo;

        if (isset($seo['pages']['home']) && is_array($payload['site'] ?? null)) {
            $home = $seo['pages']['home'];
            $payload['site']['seoTitle'] = $home['seoTitle'];
            $payload['site']['seoDescription'] = $home['seoDescription'];
        }
    }

    private function validateCanonicalOrigin(mixed $origin): string
    {
        $origin = is_string($origin) ? rtrim(trim($origin), '/') : '';
        if ($origin === '') {
            return app()->environment('production')
                ? $this->productionCanonicalOrigin()
                : 'http://localhost:3000';
        }

        $allowed = [
            $this->productionCanonicalOrigin(),
        ];
        if (! app()->environment('production')) {
            $allowed[] = 'http://localhost:3000';
            $allowed[] = 'http://localhost:3010';
        }

        if (! in_array($origin, $allowed, true)) {
            throw new \InvalidArgumentException('seo.canonicalOrigin is not on the allowlist.');
        }

        if (app()->environment('production') && ! str_starts_with($origin, 'https://')) {
            throw new \InvalidArgumentException('seo.canonicalOrigin must use HTTPS in production.');
        }

        if (str_contains($origin, '#') || str_contains($origin, '?')) {
            throw new \InvalidArgumentException('seo.canonicalOrigin must not contain query or fragment.');
        }

        return $origin;
    }

    private function productionCanonicalOrigin(): string
    {
        $configured = rtrim((string) config('brand.website_url', self::PRODUCTION_CANONICAL_ORIGIN), '/');

        return $configured !== '' ? $configured : self::PRODUCTION_CANONICAL_ORIGIN;
    }

    private function validateOgImagePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new \InvalidArgumentException('OG image must be a same-origin path starting with /.');
        }
        foreach (self::ALLOWED_OG_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }
        throw new \InvalidArgumentException('OG image path is not allowlisted.');
    }

    private function validatePlainTextField(string $value, string $label, int $maxLen): string
    {
        $value = trim($value);
        if ($value !== strip_tags($value)) {
            throw new \InvalidArgumentException("{$label} must not contain HTML.");
        }
        if (mb_strlen($value) > $maxLen) {
            throw new \InvalidArgumentException("{$label} exceeds maximum length of {$maxLen}.");
        }

        return $value;
    }

    private function validateGoogleVerificationToken(string $token): void
    {
        $token = trim($token);
        if ($token === '' || mb_strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
            throw new \InvalidArgumentException('Invalid Google site verification token.');
        }
    }

    private function validateBingVerificationToken(string $token): void
    {
        $token = trim($token);
        if ($token === '' || mb_strlen($token) > 128 || ! preg_match('/^[A-Fa-f0-9]+$/', $token)) {
            throw new \InvalidArgumentException('Invalid Bing site verification token.');
        }
    }

    private function validateGa4Id(string $id): void
    {
        $id = trim($id);
        if (! preg_match('/^G-[A-Z0-9]+$/', $id) && ! preg_match('/^GT-[A-Z0-9]+$/', $id)) {
            throw new \InvalidArgumentException('Invalid GA4 measurement id.');
        }
    }

    private function scanProhibitedSeoText(string $text, string $label): void
    {
        foreach (self::SEO_PROHIBITED_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                throw new \InvalidArgumentException("Prohibited marketing pattern in {$label}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function assertUniqueSeoTitles(array $pages): void
    {
        $seen = [];
        foreach ($pages as $pageId => $page) {
            if (! is_array($page)) {
                continue;
            }
            $title = mb_strtolower(trim((string) ($page['seoTitle'] ?? '')));
            if ($title === '') {
                continue;
            }
            if (isset($seen[$title])) {
                throw new \InvalidArgumentException(
                    "Duplicate seoTitle on pages {$seen[$title]} and {$pageId}. Each public page needs a unique title."
                );
            }
            $seen[$title] = $pageId;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $context): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported field {$context}.{$key}");
            }
        }
    }
}
