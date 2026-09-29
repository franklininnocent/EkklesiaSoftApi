<?php

namespace Modules\Notifications\Support;

use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationDefinition;

/**
 * Personal preference rows a user is allowed to see and save.
 * Parish users never see platform types. Platform users never see parish types.
 */
final class NotificationPreferenceVisibility
{
    public const AUDIENCE_PARISH = 'parish';

    public const AUDIENCE_PLATFORM = 'platform';

    public const AUDIENCE_BOTH = 'both';

    /**
     * @var array<string, array{audience: string, any_user?: bool, gates?: list<string>, parish?: list<string>, platform?: list<string>}>
     */
    private const CATALOG = [
        'donations.refund.requested' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['permission:donations.approvals'],
        ],
        'donations.refund.decided' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['permission:donations.approvals'],
        ],
        'tenants.subscription.lifecycle' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['parish_admin'],
        ],
        'pastoral.visit.assigned' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['permission:pastoral.care.view', 'permission:pastoral.care.assign'],
        ],
        'auth.password_recovery.requested' => [
            'audience' => self::AUDIENCE_BOTH,
            'parish' => ['parish_admin'],
            'platform' => ['permission:password.recovery.requests.process'],
        ],
        'auth.password_recovery.approved' => [
            'audience' => self::AUDIENCE_BOTH,
            'any_user' => true,
        ],
        'support.emergency_approval.pending' => [
            'audience' => self::AUDIENCE_PLATFORM,
            'gates' => ['permission:support.sessions.approve'],
        ],
        'application.security.threat' => [
            'audience' => self::AUDIENCE_PLATFORM,
            'gates' => ['permission:application_access.view'],
        ],
        'support.ticket.sla_warning' => [
            'audience' => self::AUDIENCE_PLATFORM,
            'gates' => ['permission:support.ops.tickets.view'],
        ],
        'support.ticket.sla_breach' => [
            'audience' => self::AUDIENCE_PLATFORM,
            'gates' => ['permission:support.ops.tickets.view'],
        ],
        'subscriptions.upgrade_request.submitted' => [
            'audience' => self::AUDIENCE_PLATFORM,
            'gates' => ['permission:subscriptions.requests.review'],
        ],
        'subscriptions.upgrade_request.decided' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['parish_admin'],
        ],
        'subscriptions.usage.threshold' => [
            'audience' => self::AUDIENCE_PARISH,
            'gates' => ['parish_admin'],
        ],
    ];

    public function visibleTo(User $user, NotificationDefinition $definition): bool
    {
        $rule = self::CATALOG[$definition->code] ?? null;
        if ($rule === null) {
            return false;
        }

        $parish = $user->tenant_id !== null;
        $audience = $rule['audience'];

        if ($audience === self::AUDIENCE_PARISH && ! $parish) {
            return false;
        }

        if ($audience === self::AUDIENCE_PLATFORM && $parish) {
            return false;
        }

        if (! empty($rule['any_user'])) {
            return true;
        }

        $gates = $parish
            ? ($rule['parish'] ?? $rule['gates'] ?? [])
            : ($rule['platform'] ?? $rule['gates'] ?? []);

        foreach ($gates as $gate) {
            if ($this->passesGate($user, $gate)) {
                return true;
            }
        }

        return false;
    }

    private function passesGate(User $user, string $gate): bool
    {
        if ($gate === 'parish_admin') {
            return $this->isParishAdmin($user);
        }

        if (str_starts_with($gate, 'permission:')) {
            return $user->hasPermission(substr($gate, strlen('permission:')));
        }

        return false;
    }

    private function isParishAdmin(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        return (bool) $user->is_primary_admin || $user->isTenantAdmin();
    }
}
