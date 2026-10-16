<?php

namespace Modules\Marketing\Services;

class MarketingPublicLinkValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertValidLinks(array $payload): void
    {
        $navigation = $payload['navigation'] ?? null;
        if (! is_array($navigation)) {
            throw new \InvalidArgumentException('Marketing content payload.navigation must be an array.');
        }
        foreach ($navigation as $index => $item) {
            $this->assertNavItem($item, "navigation[{$index}]");
        }

        $footer = $payload['footer'] ?? null;
        if (! is_array($footer)) {
            throw new \InvalidArgumentException('Marketing content payload.footer must be an object.');
        }
        $links = $footer['links'] ?? null;
        if (! is_array($links)) {
            throw new \InvalidArgumentException('Marketing content payload.footer.links must be an array.');
        }
        foreach ($links as $index => $item) {
            $this->assertNavItem($item, "footer.links[{$index}]");
        }

        $hero = $payload['hero'] ?? null;
        if (is_array($hero) && is_array($hero['ctas'] ?? null)) {
            foreach ($hero['ctas'] as $index => $cta) {
                $this->assertNavItem($cta, "hero.ctas[{$index}]");
            }
        }

        $finalCta = $payload['finalCta'] ?? null;
        if (is_array($finalCta) && is_array($finalCta['ctas'] ?? null)) {
            foreach ($finalCta['ctas'] as $index => $cta) {
                $this->assertNavItem($cta, "finalCta.ctas[{$index}]");
            }
        }
    }

    /**
     * @param  mixed  $item
     */
    private function assertNavItem(mixed $item, string $path): void
    {
        if (! is_array($item)) {
            throw new \InvalidArgumentException("{$path} must be an object.");
        }
        $href = trim((string) ($item['href'] ?? ''));
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '' || $href === '') {
            throw new \InvalidArgumentException("{$path} requires label and href.");
        }
        $this->assertAllowedHref($href, $path);
    }

    private function assertAllowedHref(string $href, string $path): void
    {
        if (str_contains($href, '://') || str_starts_with($href, '//')) {
            throw new \InvalidArgumentException("{$path}.href must not be an external URL.");
        }
        if (preg_match('/^#[a-z0-9-]+$/i', $href)) {
            return;
        }
        $allowedRoutes = [
            '/',
            '/privacy',
            '/terms',
            '/features',
            '/about',
            '/contact',
            '/solutions/catholic-parishes',
        ];
        if (in_array($href, $allowedRoutes, true)) {
            return;
        }
        if (preg_match('#^/features/[a-z0-9-]+$#', $href)) {
            return;
        }
        throw new \InvalidArgumentException("{$path}.href is not an allowed public link.");
    }
}
