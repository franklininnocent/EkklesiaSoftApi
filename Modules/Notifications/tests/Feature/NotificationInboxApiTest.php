<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationEvent;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Notifications\Services\NotificationPublisher;
use Tests\TestCase;

class NotificationInboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationDefinitionsSeeder::class);
    }

    public function test_tenant_user_lists_own_notifications(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'active' => 1]);
        $this->createUserNotification($user, 1);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/tenant/notifications');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_platform_user_cannot_access_tenant_inbox(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'active' => 1]);

        $this->actingAs($user, 'api')
            ->getJson('/api/tenant/notifications')
            ->assertNotFound();
    }

    public function test_mark_read_decrements_unread_count(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'active' => 1]);
        $row = $this->createUserNotification($user, 1);

        $this->actingAs($user, 'api')
            ->getJson('/api/tenant/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($user, 'api')
            ->patchJson('/api/tenant/notifications/'.$row->id.'/read')
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson('/api/tenant/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    private function createUserNotification(User $user, int $tenantId): UserNotification
    {
        $definition = NotificationDefinition::query()->first();

        $event = NotificationEvent::query()->create([
            'definition_id' => $definition->id,
            'event_type' => $definition->event_type,
            'category' => $definition->category,
            'module' => $definition->module,
            'priority' => 'normal',
            'inbox_scope' => InboxScope::Tenant->value,
            'tenant_id' => $tenantId,
            'title' => 'Test',
            'body' => 'Body',
            'idempotency_key' => 'test-'.uniqid('', true),
        ]);

        return UserNotification::query()->create([
            'notification_event_id' => $event->id,
            'user_id' => $user->id,
            'inbox_scope' => InboxScope::Tenant->value,
            'tenant_id' => $tenantId,
            'status' => 'unread',
        ]);
    }
}
