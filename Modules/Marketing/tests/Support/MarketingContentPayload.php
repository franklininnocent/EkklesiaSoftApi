<?php

namespace Modules\Marketing\Tests\Support;

use Modules\Marketing\Services\MarketingContentService;

final class MarketingContentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function minimal(): array
    {
        $payload = [];
        foreach (MarketingContentService::REQUIRED_PAYLOAD_KEYS as $key) {
            $payload[$key] = match ($key) {
                'site' => [
                    'name' => 'EkklesiaNexus',
                    'tagline' => 'One connected church.',
                    'slogan' => 'The Next Generation of Church Management System.',
                    'supportingLine' => 'One connected church.',
                    'seoTitle' => 'EkklesiaNexus',
                    'seoDescription' => 'Church management.',
                ],
                'navigation' => [['label' => 'Home', 'href' => '#home']],
                'hero' => [
                    'title' => 'The Next Generation of Church Management System.',
                    'supportingLine' => 'One connected church.',
                    'description' => 'Demo payload.',
                    'image' => '/images/product/dashboard.png',
                    'imageAlt' => 'Dashboard',
                    'ctas' => [],
                ],
                'screenshots' => [],
                'footer' => [
                    'statement' => 'EkklesiaNexus',
                    'links' => [['label' => 'Privacy', 'href' => '/privacy']],
                ],
                default => [],
            };
        }

        return $payload;
    }
}
