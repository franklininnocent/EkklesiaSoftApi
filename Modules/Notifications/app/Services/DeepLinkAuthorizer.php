<?php

namespace Modules\Notifications\Services;

use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationEvent;

class DeepLinkAuthorizer
{
    /**
     * @return array{route: string, params: array<string, string>, subject_status: string}
     */
    public function resolve(User $user, NotificationEvent $event): array
    {
        $route = $this->routeFor($event);
        if ($route === null) {
            return ['route' => '', 'params' => [], 'subject_status' => 'missing'];
        }

        if (! $this->userMayOpen($user, $event)) {
            return ['route' => '', 'params' => [], 'subject_status' => 'forbidden'];
        }

        return [
            'route' => $route['route'],
            'params' => $route['params'],
            'subject_status' => 'available',
        ];
    }

    public function userMayOpen(User $user, NotificationEvent $event): bool
    {
        if ($event->subject_type === null || $event->subject_id === null) {
            return true;
        }

        return true;
    }

    /**
     * @return array{route: string, params: array<string, string>}|null
     */
    private function routeFor(NotificationEvent $event): ?array
    {
        $data = $event->data ?? [];

        if (! empty($data['deep_link_route'])) {
            return [
                'route' => (string) $data['deep_link_route'],
                'params' => is_array($data['deep_link_params'] ?? null) ? $data['deep_link_params'] : [],
            ];
        }

        return match ($event->subject_type) {
            'donation_approval' => ['route' => '/donations/approvals', 'params' => []],
            'password_recovery_request' => ['route' => '/settings/forgot-password-requests', 'params' => []],
            'support_ticket' => ['route' => '/support', 'params' => ['ticket' => (string) $event->subject_id]],
            'subscription' => ['route' => '/settings/my-subscription', 'params' => []],
            'family' => ['route' => '/families/'.(string) $event->subject_id, 'params' => []],
            default => null,
        };
    }
}
