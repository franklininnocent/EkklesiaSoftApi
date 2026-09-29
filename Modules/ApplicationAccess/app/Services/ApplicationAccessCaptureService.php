<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessCaptureExemptions;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessIdentityResolver;
use Modules\ApplicationAccess\Support\ApplicationAccessViewThrottle;
use Modules\ApplicationAccess\Support\BearerTokenInspector;
use Modules\ApplicationAccess\Support\IpAddressClassifier;
use Modules\ApplicationAccess\Support\RouteNormalizer;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class ApplicationAccessCaptureService
{
    public const CAPTURED_AT_ATTRIBUTE = 'application_access.captured_at';

    public function __construct(
        private readonly ApplicationAccessCaptureExemptions $exemptions,
        private readonly ApplicationAccessRecorder $recorder,
        private readonly ApplicationAccessSessionRepository $sessions,
        private readonly ApplicationAccessIdentityResolver $identityResolver,
        private readonly ApplicationAccessViewThrottle $viewThrottle,
        private readonly BearerTokenInspector $tokenInspector,
        private readonly IpAddressClassifier $ipClassifier,
        private readonly RouteNormalizer $routeNormalizer,
        private readonly ApplicationSecuritySignalService $signals,
        private readonly ApplicationAccessThreatWriter $threatWriter,
    ) {}

    public function capture(Request $request, Response $response): void
    {
        if (! (bool) config('applicationaccess.telemetry_enabled', false)) {
            return;
        }

        if ($this->exemptions->isExempt($request)) {
            return;
        }

        if (! $request->is('api/*')) {
            return;
        }

        $ipMeta = $this->ipClassifier->classify($request->ip());
        $user = $request->user();
        $bearerToken = $request->bearerToken();
        $status = $response->getStatusCode();

        if ($bearerToken && ! $user) {
            $this->recorder->recordInvalidTokenSignal((string) ($ipMeta['ip_address'] ?? ''));

            return;
        }

        if (! $user instanceof User) {
            if (in_array($status, [401, 403], true)) {
                $this->recorder->recordSecurityEvent([
                    'id' => (string) Str::uuid(),
                    'event_type' => $status === 401 ? 'AUTH_FAILURE' : 'ACCESS_DENIED',
                    'severity' => 'MEDIUM',
                    'source_ip' => $ipMeta['ip_address'],
                    'authorization_result' => 'denied',
                    'reason_code' => $status === 401 ? 'unauthenticated' : 'forbidden',
                    'request_id' => $this->requestId($request),
                    'detected_at' => $this->capturedAt($request),
                    'metadata' => $this->routeMetadata($request),
                ]);
                $this->signals->recordAnonymousFlood((string) ($ipMeta['ip_address'] ?? ''));
            }

            $this->threatWriter->maybeRecordFromDeniedResponse($request, $response);

            return;
        }

        if (! $this->shouldCaptureRequest($request, $status)) {
            return;
        }

        $tokenId = $this->tokenInspector->extractAccessTokenId($bearerToken);
        $session = $tokenId ? $this->sessions->findByOAuthTokenId($tokenId) : null;

        if ($session) {
            $this->sessions->touchActivity($session);
        }

        $route = $this->routeNormalizer->normalize($request);
        $method = strtoupper($request->method());

        if ($method === 'GET' && $session && ! $this->viewThrottle->shouldRecord($session->id, $route['normalized_route'])) {
            return;
        }

        $action = $this->resolveAction($method, $route['route_name']);
        $eventType = $this->resolveEventType($method, $status, $route['route_name']);
        $authorizationResult = $status >= 400 ? 'denied' : 'allowed';

        $this->recorder->recordAccessEvent([
            'id' => (string) Str::uuid(),
            'access_session_id' => $session?->id,
            'user_id' => $user->id,
            'ip_address' => $ipMeta['ip_address'],
            'event_type' => $eventType,
            'module_code' => $this->resolveModuleCode($route['route_name']),
            'action' => $action,
            'route_name' => $route['route_name'],
            'normalized_route' => $route['normalized_route'],
            'http_method' => $method,
            'http_status' => $status,
            'authorization_result' => $authorizationResult,
            'tenant_id' => app(TenantContext::class)->effectiveTenantId() ?? $user->tenant_id,
            'support_session_id' => $this->supportSessionId(),
            'request_id' => $this->requestId($request),
            'occurred_at' => $this->capturedAt($request),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        if ($status === 403) {
            $this->signals->recordRepeated403(
                (string) ($ipMeta['ip_address'] ?? ''),
                $route['normalized_route']
            );
        }

        $this->threatWriter->maybeRecordFromDeniedResponse($request, $response);
    }

    private function shouldCaptureRequest(Request $request, int $status): bool
    {
        if (in_array($status, [401, 403], true)) {
            return true;
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        return $request->method() === 'GET' && $request->route()?->getName() !== null;
    }

    private function resolveAction(string $method, ?string $routeName): string
    {
        if ($routeName !== null) {
            $suffix = Str::afterLast($routeName, '.');
            $actionMap = [
                'index' => 'LIST',
                'show' => 'VIEW',
                'store' => 'CREATE',
                'update' => 'UPDATE',
                'destroy' => 'DELETE',
                'export' => 'EXPORT',
                'download' => 'DOWNLOAD',
            ];

            if (isset($actionMap[$suffix])) {
                return $actionMap[$suffix];
            }
        }

        return match ($method) {
            'GET', 'HEAD' => 'VIEW',
            'POST' => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE' => 'DELETE',
            default => 'VIEW',
        };
    }

    private function resolveEventType(string $method, int $status, ?string $routeName): string
    {
        if (in_array($status, [401, 403], true)) {
            return $status === 401 ? 'AUTH_FAILURE' : 'ACCESS_DENIED';
        }

        if ($method === 'GET' || $method === 'HEAD') {
            if ($routeName !== null && Str::endsWith($routeName, '.index')) {
                return 'LIST';
            }

            return 'VIEW';
        }

        return strtoupper($this->resolveAction($method, $routeName));
    }

    private function resolveModuleCode(?string $routeName): ?string
    {
        if ($routeName === null || ! str_contains($routeName, '.')) {
            return null;
        }

        return Str::before($routeName, '.');
    }

    /**
     * @return array<string, string>
     */
    private function routeMetadata(Request $request): array
    {
        $route = $this->routeNormalizer->normalize($request);

        return array_filter([
            'route_name' => $route['route_name'],
            'normalized_route' => $route['normalized_route'],
            'http_method' => $request->method(),
        ]);
    }

    private function requestId(Request $request): ?string
    {
        return $request->attributes->get(AssignRequestId::ATTRIBUTE)
            ?? $request->header(AssignRequestId::HEADER);
    }

    private function capturedAt(Request $request): \Illuminate\Support\Carbon
    {
        $capturedAt = $request->attributes->get(self::CAPTURED_AT_ATTRIBUTE);

        return $capturedAt instanceof \Illuminate\Support\Carbon
            ? $capturedAt
            : now();
    }

    private function supportSessionId(): ?string
    {
        if (! app()->bound(TenantContext::class)) {
            return null;
        }

        $context = app(TenantContext::class);

        return $context->isSupportSession() ? $context->supportSessionId() : null;
    }
}
