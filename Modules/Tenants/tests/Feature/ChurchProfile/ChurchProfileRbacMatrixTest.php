<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileRbacMatrixTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_deny_unauthenticated_requests(): void
    {
        $this->getJson('/api/church-profile')->assertUnauthorized();
        $this->putJson('/api/church-profile', $this->validProfilePayload())->assertUnauthorized();
        $this->getJson('/api/tenant/church-profile')->assertUnauthorized();
        $this->getJson('/api/church-profile/leadership/current')->assertUnauthorized();
        $this->getJson('/api/church-statistics')->assertUnauthorized();
        $this->getJson('/api/church-social-media')->assertUnauthorized();
    }

    #[Test]
    public function it_should_allow_viewer_to_read_but_not_mutate(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $this->seedChurchProfile($ctx['tenant']);
        $this->seedLeadershipRoles();

        $this->getJson('/api/church-profile')->assertOk();
        $this->getJson('/api/tenant/church-profile')->assertOk();
        $this->getJson('/api/church-profile/leadership/current')->assertOk();
        $this->getJson('/api/church-statistics')->assertOk();
        $this->getJson('/api/church-social-media')->assertOk();

        $this->putJson('/api/church-profile', $this->validProfilePayload())->assertForbidden();
        $this->putJson('/api/tenant/church-profile', ['name' => 'Hacked Parish'])->assertForbidden();
        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('patron.jpg'),
        ])->assertForbidden();
        $this->deleteJson('/api/church-profile/patron-image')->assertForbidden();
        $this->postJson('/api/church-statistics', [
            'year' => 2026,
            'month' => 1,
            'membership_count' => 10,
        ])->assertForbidden();
        $this->postJson('/api/church-social-media', [
            'platform' => 'facebook',
            'url' => 'https://facebook.com/test',
        ])->assertForbidden();
    }

    #[Test]
    public function it_should_allow_edit_without_delete_for_profile_but_block_patron_delete(): void
    {
        Storage::fake('public');
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $profile = $this->seedChurchProfile($ctx['tenant'], [
            'patron_image_path' => 'tenants/'.$ctx['tenant']->id.'/patron/patron_test.jpg',
        ]);
        Storage::disk('public')->put($profile->patron_image_path, 'image-bytes');

        $this->putJson('/api/church-profile', $this->validProfilePayload())->assertOk();
        $this->postJson('/api/church-profile/upload-patron-image', [
            'image' => UploadedFile::fake()->image('new-patron.jpg'),
        ])->assertOk();

        $this->deleteJson('/api/church-profile/patron-image')->assertForbidden();

        $profile->refresh();
        $this->assertNotNull($profile->patron_image_path);
    }

    #[Test]
    public function it_should_require_edit_and_delete_to_remove_patron_image(): void
    {
        Storage::fake('public');
        $ctx = $this->actingAsTenantWith(['church.settings.edit', 'church.settings.delete']);
        $path = 'tenants/'.$ctx['tenant']->id.'/patron/patron_test.jpg';
        $this->seedChurchProfile($ctx['tenant'], ['patron_image_path' => $path]);
        Storage::disk('public')->put($path, 'image-bytes');

        $this->deleteJson('/api/church-profile/patron-image')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $ctx['tenant']->id,
            'patron_image_path' => null,
        ]);
    }

    #[Test]
    public function it_should_allow_create_without_edit_for_statistics_and_social(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.create']);

        $stat = $this->postJson('/api/church-statistics', [
            'year' => 2026,
            'month' => 2,
            'membership_count' => 50,
        ]);
        $stat->assertCreated();
        $statId = $stat->json('data.id');

        $social = $this->postJson('/api/church-social-media', [
            'platform' => 'instagram',
            'url' => 'https://instagram.com/parish',
        ]);
        $social->assertCreated();

        $this->putJson("/api/church-statistics/{$statId}", ['membership_count' => 99])->assertForbidden();
        $this->putJson('/api/church-profile', $this->validProfilePayload())->assertForbidden();
    }

    #[Test]
    public function it_should_allow_tenant_admin_to_update_both_profile_surfaces(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->seedChurchProfile($ctx['tenant']);

        $this->putJson('/api/church-profile', $this->validProfilePayload(['patron_name' => 'St Peter']))
            ->assertOk()
            ->assertJsonPath('data.patron_name', 'St Peter');

        $this->putJson('/api/tenant/church-profile', ['name' => 'Certified Parish'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Certified Parish');
    }
}
