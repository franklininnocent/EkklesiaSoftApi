<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Services\NotificationPublisher;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * In-app notification samples for parish inbox (read + unread).
 */
class TenantNotificationsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for notifications demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor = TenantDemoResolver::resolveActor($tenantId);
        if (! $actor) {
            $this->command?->warn('Skipping notifications demo: no parish user.');

            return;
        }

        if (! NotificationDefinition::query()->exists()) {
            $this->call(NotificationDefinitionsSeeder::class);
        }

        $definition = NotificationDefinition::query()
            ->where('code', 'donations.refund.decided')
            ->where('active', true)
            ->first();

        if (! $definition) {
            $this->command?->warn('Skipping notifications demo: definition donations.refund.decided missing.');

            return;
        }

        $publisher = app(NotificationPublisher::class);
        $samples = [
            ['occurrence' => 'unread', 'decision' => 'approved', 'family' => 'Demo Family A'],
            ['occurrence' => 'read', 'decision' => 'declined', 'family' => 'Demo Family B'],
        ];

        $created = 0;
        foreach ($samples as $sample) {
            $occurrenceId = TenantDemoMarkers::MARKER.'_'.$sample['occurrence'];
            $intent = new NotificationIntent(
                definitionCode: 'donations.refund.decided',
                actor: $actor,
                subjectType: 'tenant_demo',
                subjectId: (string) $tenantId,
                tenantId: $tenantId,
                scope: InboxScope::Tenant,
                occurrenceId: $occurrenceId,
                data: [
                    'decision' => $sample['decision'],
                    'family_name' => $sample['family'],
                ],
                explicitRecipientIds: [(int) $actor->id],
            );

            $event = $publisher->publish($intent);
            if ($event === null) {
                continue;
            }

            $created++;

            if ($sample['occurrence'] === 'read') {
                UserNotification::query()
                    ->where('notification_event_id', $event->id)
                    ->where('user_id', $actor->id)
                    ->update(['status' => 'read', 'read_at' => now()->subHour()]);
            }
        }

        $this->command?->info(sprintf(
            'Notifications demo: %d events published for user #%d (tenant #%d).',
            $created,
            $actor->id,
            $tenantId
        ));
    }
}
