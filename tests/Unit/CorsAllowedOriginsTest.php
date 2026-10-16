<?php

namespace Tests\Unit;

use App\Support\CorsAllowedOrigins;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CorsAllowedOriginsTest extends TestCase
{
    #[Test]
    public function production_excludes_localhost_even_when_frontend_url_is_loopback(): void
    {
        $origins = CorsAllowedOrigins::resolve(
            'production',
            'http://localhost:4200',
            'https://ekklesianexus.com',
        );

        $this->assertSame(['https://ekklesianexus.com'], $origins);
    }

    #[Test]
    public function production_uses_only_configured_public_urls(): void
    {
        $origins = CorsAllowedOrigins::resolve(
            'production',
            'https://app.ekklesianexus.com',
            'https://ekklesianexus.com',
        );

        $this->assertSame([
            'https://app.ekklesianexus.com',
            'https://ekklesianexus.com',
        ], $origins);
    }

    #[Test]
    public function local_and_testing_keep_loopback_origins(): void
    {
        $origins = CorsAllowedOrigins::resolve('local', 'http://localhost:4200', null);

        $this->assertContains('http://localhost:4200', $origins);
        $this->assertContains('http://127.0.0.1:4200', $origins);
    }
}
