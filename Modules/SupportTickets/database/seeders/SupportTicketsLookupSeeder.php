<?php

namespace Modules\SupportTickets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Models\SupportQueue;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportSlaPolicy;
use Modules\SupportTickets\Support\TicketPriority;

class SupportTicketsLookupSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['slug' => 'technical_issue', 'name' => 'Technical Issue', 'sort_order' => 1, 'requires_bug_fields' => false],
            ['slug' => 'bug_report', 'name' => 'Bug Report', 'sort_order' => 2, 'requires_bug_fields' => true],
            ['slug' => 'account_user_access', 'name' => 'Account/User Access', 'sort_order' => 3, 'requires_bug_fields' => false],
            ['slug' => 'billing_subscription', 'name' => 'Billing/Subscription', 'sort_order' => 4, 'requires_bug_fields' => false],
            ['slug' => 'data_issue', 'name' => 'Data Issue', 'sort_order' => 5, 'requires_bug_fields' => false],
            ['slug' => 'feature_request', 'name' => 'Feature Request', 'sort_order' => 6, 'requires_bug_fields' => false],
            ['slug' => 'configuration_help', 'name' => 'Configuration Help', 'sort_order' => 7, 'requires_bug_fields' => false],
            ['slug' => 'security_concern', 'name' => 'Security Concern', 'sort_order' => 8, 'requires_bug_fields' => false],
            ['slug' => 'performance_issue', 'name' => 'Performance Issue', 'sort_order' => 9, 'requires_bug_fields' => false],
            ['slug' => 'integration_api', 'name' => 'Integration/API', 'sort_order' => 10, 'requires_bug_fields' => false],
            ['slug' => 'training_howto', 'name' => 'Training/How-to', 'sort_order' => 11, 'requires_bug_fields' => false],
            ['slug' => 'other', 'name' => 'Other', 'sort_order' => 12, 'requires_bug_fields' => false],
        ];

        foreach ($types as $type) {
            SupportRequestType::updateOrCreate(
                ['slug' => $type['slug']],
                array_merge(['active' => true], $type)
            );
        }

        $queues = [
            ['slug' => 'application_support', 'name' => 'Application Support', 'sort_order' => 1],
            ['slug' => 'account_access', 'name' => 'Account & Access', 'sort_order' => 2],
            ['slug' => 'billing', 'name' => 'Billing', 'sort_order' => 3],
            ['slug' => 'data_support', 'name' => 'Data Support', 'sort_order' => 4],
            ['slug' => 'security', 'name' => 'Security', 'sort_order' => 5],
            ['slug' => 'product_support', 'name' => 'Product Support', 'sort_order' => 6],
        ];

        foreach ($queues as $queue) {
            SupportQueue::updateOrCreate(['slug' => $queue['slug']], $queue);
        }

        $sla = [
            TicketPriority::LOW => [480, 2880],
            TicketPriority::NORMAL => [480, 1440],
            TicketPriority::HIGH => [240, 720],
            TicketPriority::URGENT => [120, 480],
            TicketPriority::CRITICAL => [60, 240],
        ];

        foreach ($sla as $priority => [$first, $resolution]) {
            SupportSlaPolicy::updateOrCreate(
                ['priority' => $priority],
                [
                    'first_response_minutes' => $first,
                    'resolution_minutes' => $resolution,
                ]
            );
        }

        $bugType = SupportRequestType::query()->where('slug', 'bug_report')->first();
        if ($bugType) {
            SupportCategory::updateOrCreate(
                ['request_type_id' => $bugType->id, 'name' => 'General', 'parent_id' => null],
                ['sort_order' => 1, 'active' => true]
            );
        }
    }
}
