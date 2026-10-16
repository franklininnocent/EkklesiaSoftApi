<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use PHPUnit\Framework\Attributes\Test;

class ProjectFamilyProgressApiTest extends DonationsCertificationTestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private DonationProject $project;

    /** @var array<string, Family> */
    private array $families = [];

    protected function setUp(): void
    {
        parent::setUp();

        $context = $this->actingAsTenantWith(['donations.view']);
        $this->tenantId = $context['tenant']->id;

        $this->project = DonationProject::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Parish Hall',
            'code' => 'HALL',
            'assignment_mode' => 'uniform',
            'default_family_target' => 1000,
            'target_amount' => 0,
            'installment_count' => 1,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        $stMary = BCC::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'St Mary BCC']);
        $stJoseph = BCC::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'St Joseph BCC']);

        $this->families['paid'] = $this->family('FAM001', 'Anthony Paul', $stMary->id, 1000);
        $this->families['partial'] = $this->family('FAM002', 'Beatrice Thomas', $stMary->id, 400);
        $this->families['none'] = $this->family('FAM003', 'Cyril Mathew', $stJoseph->id, 0);
        $this->families['unassigned'] = $this->family('FAM004', 'Dominic Xavier', null, 250);
    }

    #[Test]
    public function it_paginates_family_progress_with_family_details_sorted_by_outstanding_by_default(): void
    {
        $response = $this->getJson($this->url(['per_page' => 2]));

        $response->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.family_code', 'FAM003')
            ->assertJsonPath('data.data.0.head_of_family', 'Cyril Mathew')
            ->assertJsonPath('data.data.0.bcc_name', 'St Joseph BCC')
            ->assertJsonPath('data.data.0.status', 'not_started')
            ->assertJsonPath('data.data.0.outstanding_amount', 1000)
            ->assertJsonPath('data.data.1.family_code', 'FAM004');

        $this->getJson($this->url(['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.data.0.family_code', 'FAM002')
            ->assertJsonPath('data.data.1.family_code', 'FAM001');
    }

    #[Test]
    public function it_searches_by_family_code_family_name_and_head_name(): void
    {
        $this->getJson($this->url(['search' => 'fam002']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_id', $this->families['partial']->id);

        $this->getJson($this->url(['search' => 'xavier']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM004');

        $this->getJson($this->url(['search' => 'Household FAM001']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM001');

        $this->getJson($this->url(['search' => 'nobody-matches']))
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.data');
    }

    #[Test]
    public function it_filters_by_progress_status(): void
    {
        $this->getJson($this->url(['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM001');

        $partial = $this->getJson($this->url(['status' => 'partial', 'sort' => 'family_code']));
        $partial->assertOk()->assertJsonPath('data.total', 2);
        $this->assertSame(['FAM002', 'FAM004'], array_column($partial->json('data.data'), 'family_code'));

        $this->getJson($this->url(['status' => 'not_started']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM003');
    }

    #[Test]
    public function it_filters_by_bcc_and_returns_bcc_options_for_enrolled_families(): void
    {
        $stMaryId = $this->families['paid']->bcc_id;

        $response = $this->getJson($this->url(['bcc_id' => $stMaryId, 'sort' => 'family_code']));
        $response->assertOk()->assertJsonPath('data.total', 2);
        $this->assertSame(['FAM001', 'FAM002'], array_column($response->json('data.data'), 'family_code'));
        $this->assertSame(
            ['Unassigned Area', 'St Joseph BCC', 'St Mary BCC'],
            array_column($response->json('meta.filter_options.bccs'), 'name')
        );

        $this->getJson($this->url(['bcc_id' => 'unassigned']))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM004');
    }

    #[Test]
    public function it_combines_search_filters_sort_and_pagination(): void
    {
        $response = $this->getJson($this->url([
            'bcc_id' => $this->families['paid']->bcc_id,
            'status' => 'partial',
            'search' => 'FAM',
            'sort' => 'amount_collected',
            'direction' => 'asc',
            'per_page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.last_page', 1)
            ->assertJsonPath('data.data.0.family_code', 'FAM002')
            ->assertJsonPath('data.data.0.amount_collected', 400);
    }

    #[Test]
    public function it_sorts_in_both_directions_on_allowlisted_columns(): void
    {
        $asc = $this->getJson($this->url(['sort' => 'amount_collected', 'direction' => 'asc']));
        $asc->assertOk();
        $this->assertSame(['FAM003', 'FAM004', 'FAM002', 'FAM001'], array_column($asc->json('data.data'), 'family_code'));

        $desc = $this->getJson($this->url(['sort' => 'completion_percentage', 'direction' => 'desc']));
        $desc->assertOk();
        $this->assertSame(['FAM001', 'FAM002', 'FAM004', 'FAM003'], array_column($desc->json('data.data'), 'family_code'));

        $byName = $this->getJson($this->url(['sort' => 'family_name', 'direction' => 'desc']));
        $byName->assertOk();
        $this->assertSame(['FAM004', 'FAM003', 'FAM002', 'FAM001'], array_column($byName->json('data.data'), 'family_code'));
    }

    #[Test]
    public function it_rejects_invalid_list_parameters(): void
    {
        $this->getJson($this->url(['sort' => 'tenant_id']))->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson($this->url(['direction' => 'sideways']))->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson($this->url(['status' => 'paid']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson($this->url(['per_page' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->url(['page' => 0]))->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson($this->url(['search' => str_repeat('a', 121)]))->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    #[Test]
    public function it_clamps_oversized_page_sizes_to_the_platform_maximum(): void
    {
        $this->getJson($this->url(['per_page' => 500]))
            ->assertOk()
            ->assertJsonPath('data.per_page', 100);
    }

    #[Test]
    public function it_rejects_a_bcc_from_another_parish(): void
    {
        $other = $this->makeTenantUser(['donations.view']);
        $foreignBcc = BCC::factory()->create(['tenant_id' => $other['tenant']->id]);

        $this->getJson($this->url(['bcc_id' => $foreignBcc->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bcc_id');
    }

    #[Test]
    public function it_never_exposes_another_parish_project_or_families(): void
    {
        $other = $this->makeTenantUser(['donations.view']);
        Family::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'status' => 'active',
            'family_code' => 'OTHER01',
        ]);
        $foreignProject = DonationProject::create([
            'tenant_id' => $other['tenant']->id,
            'name' => 'Other Parish Hall',
            'code' => 'OTHER',
            'assignment_mode' => 'uniform',
            'default_family_target' => 500,
            'installment_count' => 1,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        $this->getJson("/api/tenant/donations/projects/{$foreignProject->id}/family-progress")->assertNotFound();

        $own = $this->getJson($this->url(['per_page' => 100, 'tenant_id' => $other['tenant']->id]));
        $own->assertOk()->assertJsonPath('data.total', 4);
        $this->assertNotContains('OTHER01', array_column($own->json('data.data'), 'family_code'));
    }

    #[Test]
    public function it_forbids_users_without_donations_view_permission(): void
    {
        $this->actingAsTenantWith(['church.settings.edit']);

        $this->getJson($this->url())->assertForbidden();
    }

    #[Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson($this->url())->assertUnauthorized();
    }

    #[Test]
    public function it_returns_not_found_for_a_malformed_project_id(): void
    {
        $this->getJson('/api/tenant/donations/projects/not-a-uuid/family-progress')->assertNotFound();
    }

    #[Test]
    public function it_keeps_the_project_dashboard_family_progress_shape(): void
    {
        $response = $this->getJson("/api/tenant/donations/projects/{$this->project->id}/dashboard");

        $response->assertOk()
            ->assertJsonPath('data.families.enrolled', 4)
            ->assertJsonPath('data.families.completed', 1)
            ->assertJsonPath('data.families.partial', 2)
            ->assertJsonPath('data.totals.family_target_total', 4000);
        $this->assertSame(
            ['family_id', 'target_amount', 'amount_collected', 'outstanding_amount', 'completion_percentage'],
            array_keys($response->json('data.family_progress.0'))
        );
    }

    #[Test]
    public function it_prefers_the_active_head_member_name_over_the_stored_head_label(): void
    {
        $family = $this->families['paid'];
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Ruth',
            'middle_name' => null,
            'last_name' => 'Anthony',
            'relationship_to_head' => 'spouse',
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Peter',
            'relationship_to_head' => 'self',
            'status' => 'active',
        ]);

        $response = $this->getJson($this->url(['search' => 'FAM001']));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_id', $family->id)
            ->assertJsonPath('data.data.0.family_code', 'FAM001')
            ->assertJsonPath('data.data.0.head_of_family', 'John Peter');
    }

    #[Test]
    public function it_never_resolves_another_parish_family_assigned_to_the_project(): void
    {
        $other = $this->makeTenantUser(['donations.view']);
        $foreignFamily = Family::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'status' => 'active',
            'family_code' => 'OTHER01',
            'head_of_family' => 'Foreign Head',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Organ Fund',
            'code' => 'ORGAN',
            'assignment_mode' => 'individual',
            'default_family_target' => 0,
            'target_amount' => 0,
            'installment_count' => 1,
            'status' => 'active',
            'raised_amount' => 0,
        ]);
        ProjectFamilyAssignment::create([
            'tenant_id' => $this->tenantId,
            'project_id' => $project->id,
            'family_id' => $foreignFamily->id,
            'target_amount' => 500,
            'amount_collected' => 0,
            'is_exempt' => false,
            'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/tenant/donations/projects/{$project->id}/family-progress");

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.family_code', null)
            ->assertJsonPath('data.data.0.head_of_family', null);
        $this->assertStringNotContainsString('OTHER01', $response->getContent());
        $this->assertStringNotContainsString('Foreign Head', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        $base = "/api/tenant/donations/projects/{$this->project->id}/family-progress";

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    private function family(string $code, string $head, ?string $bccId, float $collected): Family
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenantId,
            'status' => 'active',
            'family_code' => $code,
            'family_name' => "Household {$code}",
            'head_of_family' => $head,
            'bcc_id' => $bccId,
        ]);

        ProjectFamilyAssignment::create([
            'tenant_id' => $this->tenantId,
            'project_id' => $this->project->id,
            'family_id' => $family->id,
            'target_amount' => 1000,
            'amount_collected' => $collected,
            'is_exempt' => false,
            'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'active',
        ]);

        return $family;
    }
}
