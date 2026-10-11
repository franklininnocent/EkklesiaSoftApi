<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Marketing\Tests\Support\MarketingContentPayload;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class AdminMarketingContentTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    public function test_publish_requires_super_admin(): void
    {
        $response = $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => MarketingContentPayload::minimal(),
        ]);

        $response->assertUnauthorized();
    }

    public function test_ekklesia_admin_cannot_publish_marketing_content(): void
    {
        $this->asPlatformEkklesiaAdmin();

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => MarketingContentPayload::minimal(),
        ])->assertForbidden();

        $this->postJson('/api/admin/marketing/content/drafts', [
            'payload' => MarketingContentPayload::minimal(),
        ])->assertForbidden();
    }

    public function test_super_admin_can_publish_and_public_can_read(): void
    {
        $this->asSuperAdmin();

        $publish = $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => MarketingContentPayload::minimal(),
            'notes' => 'Test publish',
        ]);

        $publish->assertCreated()
            ->assertJsonPath('success', true);

        $this->getJson('/api/public/marketing-content')
            ->assertOk()
            ->assertJsonPath('data.hero.title', 'The Next Generation of Church Management System.');
    }

    public function test_publish_rejects_incomplete_payload(): void
    {
        $this->asSuperAdmin();

        $response = $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => ['site' => ['name' => 'X']],
        ]);

        $response->assertStatus(422);
    }

    public function test_super_admin_can_list_revisions(): void
    {
        $this->asSuperAdmin();
        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => MarketingContentPayload::minimal(),
        ])->assertCreated();

        $this->getJson('/api/admin/marketing/content/revisions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_draft_is_not_public_until_published(): void
    {
        $this->asSuperAdmin();

        $draft = $this->postJson('/api/admin/marketing/content/drafts', [
            'payload' => MarketingContentPayload::minimal(),
            'notes' => 'WIP',
        ]);
        $draft->assertCreated();
        $draftId = (int) $draft->json('data.revision_id');

        $this->getJson('/api/public/marketing-content')->assertNotFound();

        $this->postJson("/api/admin/marketing/content/revisions/{$draftId}/publish")
            ->assertCreated();

        $this->getJson('/api/public/marketing-content')->assertOk();
    }

    private function asPlatformEkklesiaAdmin(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => Role::EKKLESIA_ADMIN,
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'user_type' => null,
            'is_primary_admin' => true,
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $this->refreshUserPermissionState($user);
        Passport::actingAs($user->fresh());
    }
}
