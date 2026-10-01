<?php

namespace Modules\Family\Tests\Feature;

use Carbon\Carbon;
use Modules\Family\Testing\FamilyCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class MemberDashboardApiTest extends FamilyCertificationTestCase
{
    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/members/dashboard')->assertUnauthorized();
    }

    #[Test]
    public function it_requires_families_view_permission(): void
    {
        $tenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $user = \Modules\Authentication\Models\User::factory()->tenantUser($tenant->id)->create();
        \Laravel\Passport\Passport::actingAs($user);

        $this->getJson('/api/members/dashboard')->assertForbidden();
    }

    #[Test]
    public function it_returns_dashboard_summary_for_authorized_staff(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', 'Asia/Kolkata'));
        $household['head']->update(['date_of_birth' => '1990-06-18', 'gender' => 'male']);
        $household['spouse']->update(['date_of_birth' => '1988-01-01', 'gender' => 'female']);

        $response = $this->getJson('/api/members/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'statistics' => [
                        'total_members',
                        'active_members',
                        'members_created_this_month',
                    ],
                    'demographics' => [
                        'total',
                        'gender',
                        'age_groups',
                    ],
                    'celebrations' => [
                        'week_label',
                        'week_start',
                        'week_end',
                        'birthdays_count',
                        'anniversaries_count',
                    ],
                ],
            ]);

        $this->assertGreaterThan(0, $response->json('data.statistics.total_members'));
        $this->assertArrayHasKey('adults', $response->json('data.demographics.age_groups'));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_excludes_other_tenant_members_from_demographics(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);

        $otherTenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $this->seedHousehold($otherTenant, [
            'head_first_name' => 'Hidden',
            'head_last_name' => 'Neighbor',
            'head_dob' => '1950-01-01',
        ]);

        $response = $this->getJson('/api/members/dashboard')->assertOk();
        $total = (int) $response->json('data.demographics.total');
        $statsTotal = (int) $response->json('data.statistics.total_members');

        $this->assertSame($statsTotal, $total);
        $this->assertLessThan(10, $total);
    }
}
