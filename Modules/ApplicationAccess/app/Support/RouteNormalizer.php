<?php

namespace Modules\ApplicationAccess\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class RouteNormalizer
{
    /**
     * @return array{route_name: ?string, normalized_route: string}
     */
    public function normalize(Request $request): array
    {
        $route = $request->route();
        $routeName = $route instanceof Route ? $route->getName() : null;
        $normalized = $this->normalizedPath($request, $route);

        return [
            'route_name' => $routeName,
            'normalized_route' => $normalized,
        ];
    }

    private function normalizedPath(Request $request, ?Route $route): string
    {
        if ($route instanceof Route) {
            $uri = $route->uri();
            if ($uri !== '') {
                return '/'.ltrim($uri, '/');
            }
        }

        $path = '/'.ltrim($request->path(), '/');

        return $this->scrubDynamicSegments($path);
    }

    /**
     * Replace UUIDs and numeric IDs in unregistered paths.
     */
    private function scrubDynamicSegments(string $path): string
    {
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i',
            '{id}',
            $path
        ) ?? $path;

        return preg_replace('/\/\d+(?=\/|$)/', '/{id}', $path) ?? $path;
    }
}
