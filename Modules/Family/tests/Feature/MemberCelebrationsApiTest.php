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
        $this->assertContains($household['head']->fresh()->full_name_display, $birthdayNames);
        $this->assertNotContains($household['spouse']->fresh()->full_name_display, $birthdayNames);

        $anniversaryNames = collect($response->json('data.anniversaries'))->pluck('name')->all();
        $this->assertCount(1, $anniversaryNames);
        $this->assertStringContainsString('&', $anniversaryNames[0]);
        $this->assertSame(
            'Carlos Martinez & '.$household['spouse']->fresh()->full_name_display,
            $anniversaryNames[0]
        );

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
        $name = $response->json('data.anniversaries.0.name');
        $this->assertSame(
            $household['head']->fresh()->full_name_display.' & '.$household['spouse']->fresh()->full_name_display,
            $name
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function it_orders_anniversary_couple_male_name_before_female(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $household['spouse']->update([
            'marriage_date' => '2010-06-20',
            'marriage_spouse_name' => 'Carlos Martinez',
        ]);

        $response = $this->getJson('/api/members/celebrations/list?type=anniversaries');
        $response->assertOk();
        $this->assertSame(
            'Carlos Martinez & '.$household['spouse']->fresh()->full_name_display,
            $response->json('data.0.name')
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function it_lists_birthdays_in_the_next_seven_days_with_pagination_and_search(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $household['head']->update(['date_of_birth' => '1990-06-18']);
        $household['spouse']->update(['date_of_birth' => '1988-06-19']);

        $response = $this->getJson('/api/members/celebrations/list?type=birthdays&per_page=1&page=1');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('window.start', '2026-06-15')
            ->assertJsonPath('window.end', '2026-06-21')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('last_page', 2);

        $search = $this->getJson('/api/members/celebrations/list?type=birthdays&search='.urlencode($household['head']->first_name));
        $search->assertOk()->assertJsonPath('total', 1);

        $ranged = $this->getJson('/api/members/celebrations/list?type=birthdays&from=2026-06-15&to=2026-06-21');
        $ranged->assertOk()
            ->assertJsonPath('window.start', '2026-06-15')
            ->assertJsonPath('window.end', '2026-06-21')
            ->assertJsonPath('total', 2);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_lists_anniversaries_for_the_parish_week_matching_dashboard(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $household['spouse']->update([
            'marriage_date' => '2010-06-20',
            'marriage_spouse_name' => 'Carlos Martinez',
        ]);

        $dashboard = $this->getJson('/api/members/celebrations');
        $dashboard->assertOk()
            ->assertJsonPath('data.week.start', '2026-06-15')
            ->assertJsonPath('data.week.end', '2026-06-21');

        $list = $this->getJson('/api/members/celebrations/list?type=anniversaries');
        $list->assertOk()
            ->assertJsonPath('window.start', '2026-06-15')
            ->assertJsonPath('window.end', '2026-06-21')
            ->assertJsonPath('total', count($dashboard->json('data.anniversaries')));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_filters_celebrations_list_by_bcc(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $bcc = \Modules\BCC\Models\BCC::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Filter Circle',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));

        $household['family']->update(['bcc_id' => $bcc->id]);
        $household['head']->update(['marriage_date' => '2010-06-18', 'marriage_spouse_name' => 'Spouse Name']);

        $all = $this->getJson('/api/members/celebrations/list?type=anniversaries&from=2026-06-15&to=2026-06-21');
        $all->assertOk()->assertJsonPath('total', 1);

        $filtered = $this->getJson('/api/members/celebrations/list?type=anniversaries&from=2026-06-15&to=2026-06-21&bcc_id='.$bcc->id);
        $filtered->assertOk()->assertJsonPath('total', 1);

        $otherBcc = \Modules\BCC\Models\BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $empty = $this->getJson('/api/members/celebrations/list?type=anniversaries&from=2026-06-15&to=2026-06-21&bcc_id='.$otherBcc->id);
        $empty->assertOk()->assertJsonPath('total', 0);

        Carbon::setTestNow();
    }

    #[Test]
    public function celebrations_list_requires_families_view_permission(): void
    {
        $tenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $user = \Modules\Authentication\Models\User::factory()->tenantUser($tenant->id)->create();
        \Laravel\Passport\Passport::actingAs($user);

        $this->getJson('/api/members/celebrations/list?type=birthdays')->assertForbidden();
    }
}
