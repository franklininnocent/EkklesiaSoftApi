<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Throwable;

/**
 * Parish-facing subscription lifecycle notices (admin-granted model — no payments).
 */
class SubscriptionLifecycleNotificationPublisher
{
    public function notifyTransition(Tenant $tenant, string $operation): void
    {
        if (! config('tenants.subscription.lifecycle.mail_enabled', true)) {
            Log::info('Subscription lifecycle notice skipped (mail disabled)', [
                'tenant_id' => $tenant->id,
                'operation' => $operation,
            ]);

            return;
        }

        $recipients = $this->resolveRecipients($tenant);
        if ($recipients === []) {
            Log::info('Subscription lifecycle notice skipped (no recipients)', [
                'tenant_id' => $tenant->id,
                'operation' => $operation,
            ]);

            return;
        }

        [$subject, $body] = $this->compose($tenant, $operation);

        foreach ($recipients as $email) {
            try {
                Mail::raw($body, function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            } catch (Throwable $e) {
                Log::warning('Subscription lifecycle mail failed', [
                    'tenant_id' => $tenant->id,
                    'operation' => $operation,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Subscription lifecycle notice sent', [
            'tenant_id' => $tenant->id,
            'operation' => $operation,
            'recipient_count' => count($recipients),
        ]);
    }

    /**
     * @return list<string>
     */
    private function resolveRecipients(Tenant $tenant): array
    {
        $emails = [];

        $tenant->loadMissing(['primaryContact', 'secondaryContact']);

        if ($tenant->primaryContact?->email) {
            $emails[] = strtolower(trim((string) $tenant->primaryContact->email));
        }

        $adminEmails = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('active', 1)
            ->where(function ($query) {
                $query->where('is_primary_admin', 1)
                    ->orWhereHas('permissions', function ($q) {
                        $q->where('name', 'subscription.view');
                    });
            })
            ->pluck('email')
            ->filter()
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->all();

        $emails = array_values(array_unique(array_merge($emails, $adminEmails)));

        return array_filter($emails, fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function compose(Tenant $tenant, string $operation): array
    {
        $church = $tenant->name;

        return match ($operation) {
            'entered_expiring' => [
                "Subscription ending soon — {$church}",
                "Hello,\n\nThe subscription for {$church} is ending soon. Please contact EkklesiaSoft to renew access before it expires.\n\n— EkklesiaSoft",
            ],
            'entered_grace' => [
                "Subscription grace period — {$church}",
                "Hello,\n\nThe subscription end date for {$church} has passed. You are now in the grace period and can still save changes. Please contact EkklesiaSoft to renew.\n\n— EkklesiaSoft",
            ],
            'entered_expired' => [
                "Subscription read-only — {$church}",
                "Hello,\n\nThe subscription for {$church} has ended. Your church can still view, print, and download records, but cannot save changes. Contact EkklesiaSoft to renew.\n\n— EkklesiaSoft",
            ],
            'subscription_extended', 'plan_changed' => [
                "Subscription renewed — {$church}",
                "Hello,\n\nAccess for {$church} has been renewed. You can save changes again.\n\n— EkklesiaSoft",
            ],
            'subscription_reactivated' => [
                "Subscription reactivated — {$church}",
                "Hello,\n\nSubscription suspension for {$church} has been cleared. If your term is still past the end date, the church may remain in read-only mode until EkklesiaSoft renews access.\n\n— EkklesiaSoft",
            ],
            default => [
                "Subscription update — {$church}",
                "Hello,\n\nThere is a subscription update for {$church}. Sign in to My Subscription for details.\n\n— EkklesiaSoft",
            ],
        };
    }
}
