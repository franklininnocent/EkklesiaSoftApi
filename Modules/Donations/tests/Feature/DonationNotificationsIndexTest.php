<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationNotificationLog;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DonationNotificationsIndexTest extends DonationsCertificationTestCase
{
    #[Test]
    public function notification_index_rejects_unknown_sort_columns(): void
    {
        $this->actingAsTenantWith(['donations.view', 'donations.notifications']);

        $this->getJson('/api/tenant/donations/notifications?sort=invalid_column')->assertStatus(422);
    }

    #[Test]
    public function notification_index_sorts_by_recipient_asc(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.notifications']);
        $tenantId = $ctx['tenant']->id;

        DonationNotificationLog::create([
            'tenant_id' => $tenantId,
            'notification_type' => 'due.reminder',
            'channel' => 'whatsapp',
            'recipient' => 'Zara',
            'status' => 'sent',
        ]);
        DonationNotificationLog::create([
            'tenant_id' => $tenantId,
            'notification_type' => 'due.reminder',
            'channel' => 'whatsapp',
            'recipient' => 'Alice',
            'status' => 'sent',
        ]);

        $sorted = $this->getJson('/api/tenant/donations/notifications?sort=recipient&direction=asc&per_page=50')
            ->assertOk();

        $recipients = collect($sorted->json('data.data'))->pluck('recipient')->all();
        $this->assertSame($recipients, collect($recipients)->sort()->values()->all());
    }

    #[Test]
    public function notification_index_defaults_to_created_at_desc(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.notifications']);
        $tenantId = $ctx['tenant']->id;

        $older = DonationNotificationLog::create([
            'tenant_id' => $tenantId,
            'notification_type' => 'due.reminder',
            'channel' => 'whatsapp',
            'recipient' => 'Older',
            'status' => 'sent',
        ]);
        $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $newer = DonationNotificationLog::create([
            'tenant_id' => $tenantId,
            'notification_type' => 'due.reminder',
            'channel' => 'whatsapp',
            'recipient' => 'Newer',
            'status' => 'sent',
        ]);
        $newer->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $response = $this->getJson('/api/tenant/donations/notifications?per_page=50')->assertOk();
        $ordered = collect($response->json('data.data'))
            ->whereIn('id', [$older->id, $newer->id])
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSame([$newer->id, $older->id], $ordered);
    }
}
