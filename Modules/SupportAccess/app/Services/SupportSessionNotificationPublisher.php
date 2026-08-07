<?php

namespace Modules\SupportAccess\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportAccessRequest;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Models\SupportSessionSetting;
use Throwable;

/**
 * Publishes support session lifecycle notices per notification_mode setting.
 *
 * never     → no-op for ops channel
 * immediate → log + optional Mail to config('supportaccess.notify_to')
 * digest    → enqueue; flushed by support:flush-notification-digest
 *
 * Customer disclosure (when enabled) is independent of notification_mode.
 */
class SupportSessionNotificationPublisher
{
    public const DIGEST_KEY = 'notification_digest';

    public function __construct(
        private readonly SupportSettingsService $settings,
    ) {
    }

    public function sessionStarted(SupportSession $session): void
    {
        $this->dispatch('session_started', $session, [
            'mode' => $session->mode,
            'reason_code' => $session->reason_code,
            'ticket_ref' => $session->ticket_ref,
            'expires_at' => optional($session->expires_at)?->toIso8601String(),
        ]);
        $this->discloseToCustomer($session, 'session_started');
    }

    public function sessionEnded(SupportSession $session): void
    {
        $this->dispatch('session_ended', $session, [
            'ended_reason' => $session->ended_reason,
            'status' => $session->status,
            'ended_at' => optional($session->ended_at)?->toIso8601String(),
        ]);
        $this->discloseToCustomer($session, 'session_ended');
    }

    public function approvalRequested(SupportAccessRequest $request): void
    {
        $request->loadMissing(['tenant:id,name', 'requester:id,name,email']);

        $subject = sprintf(
            '[Support Access] Emergency approval pending — %s',
            $request->tenant?->name ?? ('tenant #'.$request->tenant_id)
        );
        $body = implode("\n", [
            'An emergency support access request is awaiting approval.',
            '',
            'Request: '.$request->id,
            'Tenant: '.($request->tenant?->name ?? '').' (#'.$request->tenant_id.')',
            'Requester: '.($request->requester?->email ?? '').' (#'.$request->requester_user_id.')',
            'Reason: '.$request->reason_code,
            'Ticket: '.($request->ticket_ref ?: '(none)'),
            'Expires: '.optional($request->expires_at)?->toIso8601String(),
            '',
        ])."\n";

        $this->deliver($subject, $body, [
            'channel' => 'approval_pending',
            'request_id' => $request->id,
            'tenant_id' => $request->tenant_id,
        ]);
    }

    /**
     * Flush digest queue into one combined notice. Returns number of items sent.
     */
    public function flushDigest(): int
    {
        $items = $this->readDigestQueue();
        if ($items === []) {
            return 0;
        }

        $lines = array_map(static function (array $item): string {
            $tenant = $item['tenant_name'] ?? ('#'.$item['tenant_id']);
            $actor = $item['actor_email'] ?? ('user#'.$item['actor_user_id']);

            return sprintf(
                '- [%s] %s | tenant=%s | actor=%s | session=%s',
                $item['queued_at'] ?? '',
                $item['event'] ?? 'event',
                $tenant,
                $actor,
                $item['session_id'] ?? '',
            );
        }, $items);

        $subject = sprintf('[Support Access] Digest (%d event(s))', count($items));
        $body = "Support Access digest\n\n".implode("\n", $lines)."\n";

        $this->deliver($subject, $body, [
            'channel' => 'digest_flush',
            'count' => count($items),
        ]);

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => self::DIGEST_KEY],
            ['value' => ['items' => []]]
        );

        return count($items);
    }

    private function discloseToCustomer(SupportSession $session, string $event): void
    {
        $ops = $this->settings->getOpsSettings();
        if (! ($ops['customer_disclosure_enabled'] ?? false)) {
            return;
        }

        $session->loadMissing(['tenant:id,name,slug', 'supportUser:id,name,email']);

        $emails = User::query()
            ->where('tenant_id', $session->tenant_id)
            ->whereNotNull('email')
            ->where(function ($query): void {
                $query->where('is_primary_admin', true)
                    ->orWhereHas('roles', function ($roles): void {
                        $roles->where('name', 'Administrator');
                    });
            })
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $subject = sprintf(
            '[EkklesiaSoft] Support access %s — %s',
            $event === 'session_started' ? 'started' : 'ended',
            $session->tenant?->name ?? ('tenant #'.$session->tenant_id)
        );

        $body = implode("\n", [
            'Your parish environment received platform support access.',
            '',
            'Event: '.$event,
            'Tenant: '.($session->tenant?->name ?? '').' (#'.$session->tenant_id.')',
            'Support engineer: '.($session->supportUser?->email ?? 'unknown'),
            'Mode: '.$session->mode,
            'Reason: '.$session->reason_code,
            'Ticket: '.($session->ticket_ref ?: '(none)'),
            'Session: '.$session->id,
            'Time: '.now()->toIso8601String(),
            '',
            'Support never signs in as one of your parish users.',
            '',
        ])."\n";

        Log::info('support_access.customer_disclosure', [
            'event' => $event,
            'support_session_id' => $session->id,
            'effective_tenant_id' => $session->tenant_id,
            'recipient_count' => count($emails),
        ]);

        if ($emails === []) {
            Log::info('support_access.customer_disclosure.no_recipients', [
                'support_session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
            ]);

            return;
        }

        foreach ($emails as $email) {
            try {
                Mail::raw($body, function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            } catch (Throwable $e) {
                Log::error('support_access.customer_disclosure.mail_failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function dispatch(string $event, SupportSession $session, array $extra): void
    {
        $mode = $this->settings->getOpsSettings()['notification_mode'] ?? 'never';
        if ($mode === 'never') {
            return;
        }

        $session->loadMissing(['tenant:id,name,slug', 'supportUser:id,name,email']);

        $payload = array_merge([
            'event' => $event,
            'session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'tenant_name' => $session->tenant?->name,
            'actor_user_id' => $session->support_user_id,
            'actor_email' => $session->supportUser?->email,
            'queued_at' => now()->toIso8601String(),
        ], $extra);

        if ($mode === 'digest') {
            $this->enqueueDigest($payload);

            return;
        }

        $subject = sprintf(
            '[Support Access] %s — %s',
            $event === 'session_started' ? 'Session started' : 'Session ended',
            $session->tenant?->name ?? ('tenant #'.$session->tenant_id)
        );

        $body = $this->formatImmediateBody($payload);
        $this->deliver($subject, $body, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueueDigest(array $payload): void
    {
        $items = $this->readDigestQueue();
        $items[] = $payload;

        if (count($items) > 500) {
            $items = array_slice($items, -500);
        }

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => self::DIGEST_KEY],
            ['value' => ['items' => array_values($items)]]
        );

        Log::info('support_access.notification.digest_enqueued', [
            'event' => $payload['event'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'queue_size' => count($items),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readDigestQueue(): array
    {
        $row = SupportSessionSetting::query()->where('key', self::DIGEST_KEY)->first();
        if (! $row || ! is_array($row->value)) {
            return [];
        }

        $items = $row->value['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatImmediateBody(array $payload): string
    {
        $lines = [
            'Support Access notification',
            '',
            'Event: '.($payload['event'] ?? ''),
            'Session: '.($payload['session_id'] ?? ''),
            'Tenant: '.($payload['tenant_name'] ?? '').' (#'.($payload['tenant_id'] ?? '').')',
            'Actor: '.($payload['actor_email'] ?? '').' (#'.($payload['actor_user_id'] ?? '').')',
        ];

        foreach (['mode', 'reason_code', 'ticket_ref', 'ended_reason', 'status', 'expires_at', 'ended_at'] as $key) {
            if (! empty($payload[$key])) {
                $lines[] = ucfirst(str_replace('_', ' ', $key)).': '.$payload[$key];
            }
        }

        $lines[] = '';
        $lines[] = 'Time: '.($payload['queued_at'] ?? now()->toIso8601String());

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function deliver(string $subject, string $body, array $context): void
    {
        Log::info('support_access.notification', array_merge($context, [
            'subject' => $subject,
        ]));

        $to = trim((string) config('supportaccess.notify_to', ''));
        if ($to === '') {
            Log::info('support_access.notification.skipped_mail', [
                'reason' => 'SUPPORT_NOTIFY_TO / supportaccess.notify_to empty',
                'subject' => $subject,
            ]);

            return;
        }

        try {
            Mail::raw($body, function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });
        } catch (Throwable $e) {
            Log::error('support_access.notification.mail_failed', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
