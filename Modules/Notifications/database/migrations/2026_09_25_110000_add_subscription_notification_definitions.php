<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Notifications\Models\NotificationDefinition;

/**
 * Plan request and usage threshold notices (Subscriptions module). Insert-only: existing rows
 * edited by administrators are left untouched.
 */
return new class extends Migration
{
    private const CODES = [
        'subscriptions.upgrade_request.submitted',
        'subscriptions.upgrade_request.decided',
        'subscriptions.usage.threshold',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            return;
        }

        $rows = [
            [
                'code' => 'subscriptions.upgrade_request.submitted',
                'event_type' => 'subscription.upgrade_request.submitted',
                'title_template' => 'Plan request from {church_name}',
                'body_template' => '{church_name} asked to move to {plan_name}. Review it in Subscription requests.',
                'priority' => 'normal',
                'mandatory' => false,
                'recipient_strategy' => 'permission:subscriptions.requests.review',
            ],
            [
                'code' => 'subscriptions.upgrade_request.decided',
                'event_type' => 'subscription.upgrade_request.decided',
                'title_template' => 'Plan request: {status_label}',
                'body_template' => 'Your request for {plan_name} was reviewed: {status_label}. Open My Subscription for details.',
                'priority' => 'high',
                'mandatory' => true,
                'recipient_strategy' => 'primary_admins',
            ],
            [
                'code' => 'subscriptions.usage.threshold',
                'event_type' => 'subscription.usage.threshold',
                'title_template' => '{limit_name}: {usage} of {limit} used',
                'body_template' => 'Your church is {level_phrase} its plan limit for {limit_name}. See plan options in My Subscription.',
                'priority' => 'normal',
                'mandatory' => false,
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
