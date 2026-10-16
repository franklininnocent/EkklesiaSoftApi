<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\ApplicationAccess\Support\RouteNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouteNormalizerTest extends TestCase
{
    #[Test]
    public function it_uses_route_uri_with_placeholders(): void
    {
        $normalizer = new RouteNormalizer;
        $request = Request::create('/api/tenant/members/42', 'GET');
        $route = new Route(['GET'], 'api/tenant/members/{member}', fn () => null);
        $route->name('tenant.members.show');
        $request->setRouteResolver(fn () => $route);

        $result = $normalizer->normalize($request);

        $this->assertSame('tenant.members.show', $result['route_name']);
        $this->assertSame('api/tenant/members/{member}', ltrim($result['normalized_route'], '/'));
    }

    #[Test]
    public function it_scrubs_ids_from_unnamed_paths(): void
    {
        $normalizer = new RouteNormalizer;
        $request = Request::create(
            '/api/tenant/members/550e8400-e29b-41d4-a716-446655440000?token=secret',
            'GET'
        );

        $result = $normalizer->normalize($request);

        $this->assertStringNotContainsString('secret', $result['normalized_route']);
        $this->assertStringContainsString('{id}', $result['normalized_route']);
    }
}
