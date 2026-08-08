<?php

namespace Modules\MinistriesAssociations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Services\SubscriptionService;
use Symfony\Component\HttpFoundation\Response;

class EnsureMinistriesFeatureEnabled
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        $result = $this->subscriptionService->evaluateModuleAccess($user->tenant, 'ministries_associations');
        if (! $result['allowed']) {
            $message = match ($result['reason']) {
                'subscription_blocked' => 'Your subscription has ended or is suspended. Contact EkklesiaSoft or your administrator to restore access.',
                'account_inactive' => 'This church account is inactive.',
                default => 'Ministries & Associations is not enabled for this church.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'reason' => $result['reason'],
                'subscription_status' => $result['status'],
                'errors' => [],
            ], 403);
        }

        return $next($request);
    }
}
