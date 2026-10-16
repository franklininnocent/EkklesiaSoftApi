<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Notifications\Models\NotificationDefinition;

return new class extends Migration
{
    private const CODES = [
        'subscriptions.billing.offline_claim.submitted',
        'subscriptions.billing.offline_claim.decided',
        'subscriptions.billing.payment.fulfilled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            return;
        }

        $rows = [
            [
                'code' => 'subscriptions.billing.offline_claim.submitted',
                'event_type' => 'subscription.billing.offline_claim.submitted',
                'title_template' => 'Offline payment claim from {church_name}',
                'body_template' => '{church_name} submitted an offline payment claim for review.',
                'priority' => 'normal',
                'mandatory' => false,
                'recipient_strategy' => 'permission:subscriptions.offline.review',
            ],
            [
                'code' => 'subscriptions.billing.offline_claim.decided',
                'event_type' => 'subscription.billing.offline_claim.decided',
                'title_template' => 'Offline payment: {status_label}',
                'body_template' => 'Your offline payment claim was updated: {status_label}.',
                'priority' => 'high',
                'mandatory' => true,
                'recipient_strategy' => 'primary_admins',
            ],
            [
                'code' => 'subscriptions.billing.payment.fulfilled',
                'event_type' => 'subscription.billing.payment.fulfilled',
                'title_template' => 'Subscription payment confirmed',
                'body_template' => 'Your church subscription was updated after payment {reference}.',
                'priority' => 'high',
                'mandatory' => true,
                'recipient_strategy' => 'primary_admins',
            ],
        ];

        foreach ($rows as $row) {
            NotificationDefinition::query()->firstOrCreate(['code' => $row['code']], $row + [
                'category' => 'operations',
                'module' => 'Subscriptions',
                'channels' => ['in_app' => true, 'email' => false, 'push' => false],
                'notify_actor' => false,
                'collapse_mode' => 'replace_unread',
                'allows_delete' => true,
                'active' => true,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_definitions')) {
            NotificationDefinition::query()->whereIn('code', self::CODES)->update(['active' => false]);
        }
    }
};
