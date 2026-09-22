<?php

namespace Modules\Family\Tests\Feature;

use Carbon\Carbon;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Testing\FamilyCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class MemberCelebrationsApiTest extends FamilyCertificationTestCase
{
    #[Test]
    public function it_returns_birthdays_and_anniversaries_for_the_current_week(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $household['head']->update(['date_of_birth' => '1990-06-18']);
        $household['spouse']->update([
            'date_of_birth' => '1988-01-01',
            'marriage_date' => '2010-06-20',
            'marriage_spouse_name' => 'Carlos Martinez',
        ]);

        $response = $this->getJson('/api/members/celebrations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.week.start', '2026-06-15')
            ->assertJsonPath('data.week.end', '2026-06-21');

        $birthdayNames = collect($response->json('data.birthdays'))->pluck('name')->all();
        $this->assertContains(trim("{$household['head']->first_name} {$household['head']->last_name}"), $birthdayNames);
        $this->assertNotContains(trim("{$household['spouse']->first_name} {$household['spouse']->last_name}"), $birthdayNames);

        $anniversaryNames = collect($response->json('data.anniversaries'))->pluck('name')->all();
        $this->assertCount(1, $anniversaryNames);
        $this->assertStringContainsString('&', $anniversaryNames[0]);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_excludes_other_tenant_members_from_celebrations(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);

        $otherTenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $otherHousehold = $this->seedHousehold($otherTenant, [
            'head_first_name' => 'Hidden',
            'head_last_name' => 'Neighbor',
            'head_dob' => '1990-06-18',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $response = $this->getJson('/api/members/celebrations');
        $response->assertOk();

        $birthdayIds = collect($response->json('data.birthdays'))->pluck('id')->all();
        $this->assertNotContains($otherHousehold['head']->id, $birthdayIds);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_requires_families_view_permission(): void
    {
        $tenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $user = \Modules\Authentication\Models\User::factory()->tenantUser($tenant->id)->create();
        \Laravel\Passport\Passport::actingAs($user);

        $this->getJson('/api/members/celebrations')->assertForbidden();
    }

    #[Test]
    public function it_blocks_parishioners_from_celebrations(): void
    {
        $tenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->getJson('/api/members/celebrations')->assertForbidden();
    }

    #[Test]
    public function it_deduplicates_anniversaries_per_family(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $marriageDate = '2010-06-20';
        $household['head']->update([
            'marriage_date' => $marriageDate,
            'marriage_spouse_name' => 'Maria Martinez',
        ]);
        $household['spouse']->update([
            'marriage_date' => $marriageDate,
            'marriage_spouse_name' => 'Carlos Martinez',
        ]);

        $response = $this->getJson('/api/members/celebrations');
        $response->assertOk();

        $this->assertCount(1, $response->json('data.anniversaries'));

        Carbon::setTestNow();
    }
}
