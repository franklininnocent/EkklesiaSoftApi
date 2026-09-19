<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Mail\PasswordRecoveryCompletedMail;
use Modules\Authentication\Mail\PasswordRecoveryDailyLimitMail;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class RecoveryAdminNotifier
{
    public function notifyResetCompleted(User $target): void
    {
        if (! config('authentication.recovery.mail_enabled', true)) {
            return;
        }

        $tenantName = null;
        if ($target->tenant_id !== null) {
            $tenant = Tenant::query()->find($target->tenant_id);
            $tenantName = $tenant?->name;
        }

        foreach ($this->resolveSecurityRecipients($target) as $email) {
            Mail::to($email)->queue(new PasswordRecoveryCompletedMail(
                targetUserName: (string) $target->name,
                targetUserEmail: (string) $target->email,
                tenantName: $tenantName,
                completedAt: now()->toIso8601String(),
            ));
        }
    }

    public function notifyDailyLimitReached(User $target, int $attemptCount): void
    {
        if (! config('authentication.recovery.mail_enabled', true)) {
            return;
        }

        $tenantName = null;
        if ($target->tenant_id !== null) {
            $tenant = Tenant::query()->find($target->tenant_id);
            $tenantName = $tenant?->name;
        }

        foreach ($this->resolveSecurityRecipients($target) as $email) {
            Mail::to($email)->queue(new PasswordRecoveryDailyLimitMail(
                targetUserName: (string) $target->name,
                targetUserEmail: (string) $target->email,
                tenantName: $tenantName,
                attemptCount: $attemptCount,
                detectedAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function resolveSecurityRecipients(User $target): array
    {
        $emails = [];

        if ($target->isSuperAdmin() || $this->isTenantDefaultAdministrator($target)) {
            $emails = array_merge($emails, $this->platformAdminEmails($target));
        } else {
            $emails = array_merge($emails, $this->tenantAdministratorEmails($target));
        }

        $normalizedTarget = strtolower(trim((string) $target->email));

        return array_values(array_filter(array_unique(array_map(
            static fn (string $email) => strtolower(trim($email)),
            $emails
        )), static fn (string $email) => $email !== ''
            && $email !== $normalizedTarget
            && filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @return list<string>
     */
    private function tenantAdministratorEmails(User $target): array
    {
        if ($target->tenant_id === null) {
            return $this->platformAdminEmails($target);
        }

        $tenant = Tenant::query()
            ->with(['primaryContact'])
            ->find($target->tenant_id);

        if (! $tenant) {
            return [];
        }

        $emails = [];

        if ($tenant->primaryContact?->email) {
            $emails[] = (string) $tenant->primaryContact->email;
        }

        $primaryAdmin = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_primary_admin', true)
            ->where('active', 1)
            ->value('email');

        if ($primaryAdmin) {
            $emails[] = (string) $primaryAdmin;
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function platformAdminEmails(User $exclude): array
    {
        $emails = [];

        $configured = config('authentication.super_admin.email');
        if (is_string($configured) && $configured !== '') {
            $emails[] = $configured;
        }

        $platformAdmins = User::query()
            ->whereNull('tenant_id')
            ->where('active', 1)
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', [Role::SUPER_ADMIN, Role::EKKLESIA_ADMIN])
                    ->where('active', 1);
            })
            ->pluck('email')
            ->all();

        return array_merge($emails, $platformAdmins);
    }

    private function isTenantDefaultAdministrator(User $user): bool
    {
        return (bool) $user->is_primary_admin || $user->isTenantAdmin();
    }
}
