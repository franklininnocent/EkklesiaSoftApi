<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileDualApiTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_return_ecclesiastical_fields_from_church_profile_and_shell_from_tenant_endpoint(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $ctx['tenant']->update(['name' => 'Holy Family Parish', 'slogan' => 'Faith and Hope']);
        $this->seedChurchProfile($ctx['tenant'], [
            'patron_name' => 'St Anne',
            'about' => 'Ecclesiastical about text',
        ]);

        $ecclesiastical = $this->getJson('/api/church-profile')->assertOk();
        $ecclesiastical->assertJsonPath('data.patron_name', 'St Anne');
        $ecclesiastical->assertJsonPath('data.about', 'Ecclesiastical about text');

        $shell = $this->getJson('/api/tenant/church-profile')->assertOk();
        $shell->assertJsonPath('data.name', 'Holy Family Parish');
        $shell->assertJsonPath('data.slogan', 'Faith and Hope');
        $shell->assertJsonPath('data.church_profile.patron_name', 'St Anne');
    }

    #[Test]
    public function it_should_update_tenant_shell_without_overwriting_church_profile_identity(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $profile = $this->seedChurchProfile($ctx['tenant'], [
            'patron_name' => 'St Luke',
            'about' => 'Original ecclesiastical about',
        ]);

        $this->putJson('/api/tenant/church-profile', [
            'name' => 'Renamed Parish Shell',
            'slogan' => 'New slogan',
            'about' => 'Tenant-level about should not replace church_profiles row',
        ])->assertOk();

        $ctx['tenant']->refresh();
        $this->assertSame('Renamed Parish Shell', $ctx['tenant']->name);
        $this->assertSame('New slogan', $ctx['tenant']->slogan);

        $profile->refresh();
        $this->assertSame('St Luke', $profile->patron_name);
        $this->assertSame('Original ecclesiastical about', $profile->about);
    }

    #[Test]
    public function it_should_update_church_profile_without_renaming_tenant(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $ctx['tenant']->update(['name' => 'Immutable Tenant Name']);
        $profile = $this->seedChurchProfile($ctx['tenant'], ['patron_name' => 'St Mark']);

        $this->putJson('/api/church-profile', $this->validProfilePayload([
            'patron_name' => 'St Matthew',
            'about' => 'Updated through ecclesiastical endpoint',
        ]))->assertOk();

        $profile->refresh();
        $this->assertSame('St Matthew', $profile->patron_name);
        $this->assertSame('Updated through ecclesiastical endpoint', $profile->about);
        $this->assertSame('Immutable Tenant Name', $ctx['tenant']->fresh()->name);
    }

    #[Test]
    public function it_should_auto_create_missing_church_profile_on_get(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->assertDatabaseMissing('church_profiles', ['tenant_id' => $ctx['tenant']->id]);

        $response = $this->getJson('/api/church-profile')->assertOk();
        $profileId = $response->json('data.id');
        $this->assertNotNull($profileId);

        $this->assertDatabaseHas('church_profiles', [
            'id' => $profileId,
            'tenant_id' => $ctx['tenant']->id,
        ]);
    }
}
