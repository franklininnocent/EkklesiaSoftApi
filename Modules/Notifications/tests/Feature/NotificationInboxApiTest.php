<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationEvent;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\InboxScope;
use Modules\Tenants\Models\Tenant;
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
        $tenant = $this->writableTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->createUserNotification($user, (int) $tenant->id);

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
        $tenant = $this->writableTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $row = $this->createUserNotification($user, (int) $tenant->id);

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

    public function test_user_cannot_read_or_update_another_users_notification(): void
    {
        $ownerTenant = $this->writableTenant();
        $intruderTenant = $this->writableTenant();
        $owner = User::factory()->create(['tenant_id' => $ownerTenant->id, 'active' => 1]);
        $intruder = User::factory()->create(['tenant_id' => $intruderTenant->id, 'active' => 1]);
        $row = $this->createUserNotification($owner, (int) $ownerTenant->id);

        $this->actingAs($intruder, 'api')
            ->getJson('/api/tenant/notifications/'.$row->id)
            ->assertNotFound();

        $this->actingAs($intruder, 'api')
            ->patchJson('/api/tenant/notifications/'.$row->id.'/read')
            ->assertNotFound();

        $this->assertSame('unread', $row->fresh()->status);
    }

    private function writableTenant(): Tenant
    {
        return Tenant::factory()->create([
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);
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
