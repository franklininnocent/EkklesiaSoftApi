<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Services\Reports\DonationReportExportOrchestrator;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportExportFormat;
use Modules\Donations\Support\Reports\SafeCsvWriter;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenantContext;

class DonationReportsFrameworkTest extends DonationsCertificationTestCase
{
    use InteractsWithTenantContext;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        ['tenant' => $this->tenant, 'user' => $this->user] = $this->makeTenantUser([
            'donations.view',
            'donations.reports',
            'donations.collect',
        ]);

        $this->bindTenantContext($this->user);
        Passport::actingAs($this->user);

        DonationSetting::create([
            'tenant_id' => $this->tenant->id,
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'default_currency' => 'INR',
        ]);
    }

    #[Test]
    public function it_lists_report_catalog(): void
    {
        $this->getJson('/api/tenant/donations/reports/catalog')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [['key', 'label', 'date_semantic']],
                'meta' => ['default_report'],
            ]);
    }

    #[Test]
    public function it_hides_parish_comparison_from_parish_tenant_catalog(): void
    {
        $this->tenant->forceFill(['tenant_tier' => 'parish'])->save();

        $keys = collect($this->getJson('/api/tenant/donations/reports/catalog')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertFalse($keys->contains(DonationReportCatalog::TYPE_PARISH_COMPARISON));
    }

    #[Test]
    public function it_includes_parish_comparison_in_diocese_tenant_catalog(): void
    {
        $this->tenant->forceFill(['tenant_tier' => 'diocese'])->save();

        $keys = collect($this->getJson('/api/tenant/donations/reports/catalog')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertTrue($keys->contains(DonationReportCatalog::TYPE_PARISH_COMPARISON));
    }

    #[Test]
    public function it_previews_payments_with_date_filter(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-OLD',
            'payer_name' => 'Old Payer',
            'payment_date' => '2026-01-15',
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-NEW',
            'payer_name' => 'New Payer',
            'payment_date' => '2026-06-15',
            'amount' => 200,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('data.pagination.total'));
        $this->assertEquals(200, (float) $response->json('data.footer.amount'));
        $this->assertEquals(200, (float) $response->json('data.totals.collected_gross'));
        $this->assertStringContainsString('1 payments', (string) $response->json('data.footer.payment_number'));
    }

    #[Test]
    public function it_keeps_payment_footer_totals_across_preview_pages(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        foreach ([100, 250, 50] as $index => $amount) {
            DonationPayment::create([
                'tenant_id' => $this->tenant->id,
                'family_id' => $family->id,
                'payment_number' => 'PAY-PAGE-'.$index,
                'payer_name' => 'Paged Payer',
                'payment_date' => '2026-06-1'.($index + 1),
                'amount' => $amount,
                'currency' => 'INR',
                'method' => 'cash',
                'status' => 'succeeded',
                'source_type' => 'general',
            ]);
        }

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'per_page' => 1,
            'page' => 1,
        ]))->assertOk();

        $this->assertCount(1, $response->json('data.rows'));
        $this->assertSame(3, (int) $response->json('data.pagination.total'));
        $this->assertEquals(400, (float) $response->json('data.footer.amount'));
        $this->assertEquals(400, (float) $response->json('data.totals.collected_gross'));
    }

    #[Test]
    public function it_exports_csv_using_stored_filters(): void
    {
        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $export = DonationReportExport::query()->first();
        $this->assertNotNull($export);
        $this->assertSame('2026-06-01', $export->filters['date_from'] ?? null);
        $this->assertNotNull($export->file_path);
    }

    #[Test]
    public function it_blocks_cross_tenant_export_download(): void
    {
        $other = Tenant::factory()->active()->create();
        $export = DonationReportExport::create([
            'tenant_id' => $other->id,
            'report_type' => 'payments',
            'filters' => ['report_type' => 'payments'],
            'status' => 'completed',
            'file_path' => 'reports/fake.csv',
        ]);

        $this->getJson('/api/tenant/donations/reports/exports/'.$export->id.'/download')
            ->assertNotFound();
    }

    #[Test]
    public function it_replays_as_of_outstanding_excluding_later_payments(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);
        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'Jun 2026',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'due_date' => '2026-06-15',
            'amount_due' => 500,
            'amount_paid' => 200,
            'status' => 'partially_paid',
        ]);

        $early = DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-EARLY',
            'payer_name' => 'Early',
            'payment_date' => '2026-06-10',
            'amount' => 200,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $early->id,
            'allocatable_type' => 'due',
            'allocatable_id' => $due->id,
            'amount' => 200,
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'outstanding',
            'as_of_date' => '2026-06-12',
            'view' => 'totals',
        ]));

        $response->assertOk();
        $collectable = (float) $response->json('data.rows.3.amount');
        $this->assertEqualsWithDelta(300.0, $collectable, 0.01);
    }

    #[Test]
    public function csv_writer_sanitizes_formula_cells(): void
    {
        $this->assertStringStartsWith("'", SafeCsvWriter::sanitizeCell('=cmd'));
    }

    #[Test]
    public function it_returns_operational_print_html(): void
    {
        $this->get('/api/tenant/donations/reports/print?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8');
    }

    #[Test]
    public function it_reconciles_payments_preview_gross_total_in_xlsx_export(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-XLSX',
            'payer_name' => 'Excel Payer',
            'payment_date' => '2026-06-15',
            'amount' => 200,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $gross = (float) $preview->json('data.totals.collected_gross');

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'export_format' => 'xlsx',
        ])->assertCreated();

        $export = DonationReportExport::query()->orderByDesc('created_at')->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $sheet = IOFactory::load(storage_path('app/'.$export->file_path))->getActiveSheet();
        $found = false;
        foreach ($sheet->getRowIterator() as $row) {
            $label = (string) $sheet->getCell('A'.$row->getRowIndex())->getValue();
            if ($label === 'collected gross') {
                $value = (string) $sheet->getCell('B'.$row->getRowIndex())->getValue();
                $this->assertStringContainsString((string) (int) $gross, preg_replace('/[^\d]/', '', $value));
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        @unlink(storage_path('app/'.$export->file_path));
    }

    #[Test]
    public function it_reconciles_payments_preview_total_with_csv_row_count(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-JUN',
            'payer_name' => 'June Payer',
            'payment_date' => '2026-06-15',
            'amount' => 200,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $previewTotal = (int) $preview->json('data.pagination.total');

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertCreated();

        $export = DonationReportExport::query()->first();
        $this->assertNotNull($export);
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $this->assertSame('completed', $export->status);
        $this->assertSame($previewTotal, (int) $export->row_count);
    }

    #[Test]
    public function it_rejects_unsupported_filter_for_report_type(): void
    {
        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'action_type' => 'refund',
        ]))->assertUnprocessable();
    }

    #[Test]
    public function it_rejects_unknown_bcc_for_preview(): void
    {
        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'preset' => 'this_month',
            'bcc_id' => '999999999',
        ]))->assertUnprocessable();
    }

    #[Test]
    public function it_includes_preview_rows_in_operational_print_html(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PRINT-ME',
            'payer_name' => 'Printable Payer',
            'payment_date' => '2026-06-15',
            'amount' => 150,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $html = $this->get('/api/tenant/donations/reports/print?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('PAY-PRINT-ME', (string) $html);
        $this->assertStringContainsString('Printable Payer', (string) $html);
    }

    #[Test]
    public function it_expires_completed_exports_past_ttl(): void
    {
        Storage::fake('local');
        $path = 'reports/test-expire.csv';
        Storage::disk('local')->put($path, 'a,b');

        $export = DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'payments',
            'filters' => ['report_type' => 'payments'],
            'status' => 'completed',
            'file_path' => $path,
            'expires_at' => now()->subDay(),
        ]);

        Artisan::call('donations:expire-report-exports');

        $export->refresh();
        $this->assertSame('expired', $export->status);
        $this->assertNull($export->file_path);
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function it_dedupes_identical_export_requests_while_queued(): void
    {
        Queue::fake();

        $payload = [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ];

        $first = $this->postJson('/api/tenant/donations/reports/export', $payload)->assertCreated();
        $second = $this->postJson('/api/tenant/donations/reports/export', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DonationReportExport::query()->count());
    }

    #[Test]
    public function it_returns_too_many_requests_when_tenant_export_cap_reached(): void
    {
        Queue::fake();

        for ($i = 0; $i < 10; $i++) {
            DonationReportExport::create([
                'tenant_id' => $this->tenant->id,
                'report_type' => 'payments',
                'filters' => ['report_type' => 'payments', 'date_from' => "2026-01-{$i}"],
                'filter_hash' => 'hash-'.$i,
                'status' => 'queued',
                'requested_by' => $this->user->id,
            ]);
        }

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-12-01',
            'date_to' => '2026-12-31',
        ])->assertStatus(429);
    }

    #[Test]
    public function it_downloads_a_completed_tenant_export(): void
    {
        $directory = storage_path('app/reports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $relative = 'reports/tenant-download-test.csv';
        file_put_contents(storage_path('app/'.$relative), "col\nvalue\n");

        $export = DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'payments',
            'filters' => ['report_type' => 'payments'],
            'status' => 'completed',
            'file_path' => $relative,
            'expires_at' => now()->addWeek(),
        ]);

        $this->get('/api/tenant/donations/reports/exports/'.$export->id.'/download')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        @unlink(storage_path('app/'.$relative));
    }

    #[Test]
    public function it_lists_completed_exports_as_downloadable(): void
    {
        DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'payments',
            'filters' => ['report_type' => 'payments', 'export_format' => 'pdf'],
            'status' => 'completed',
            'file_path' => 'reports/missing-on-disk.pdf',
            'expires_at' => now()->addWeek(),
            'requested_by' => $this->user->id,
        ]);

        $this->getJson('/api/tenant/donations/reports/exports')
            ->assertOk()
            ->assertJsonPath('data.data.0.downloadable', true)
            ->assertJsonPath('data.data.0.status', 'completed')
            ->assertJsonPath('data.data.0.export_format', 'pdf')
            ->assertJsonPath('data.data.0.requested_by_name', $this->user->name);
    }

    #[Test]
    public function it_filters_export_history_on_the_server(): void
    {
        $recent = DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'payments',
            'filters' => ['report_type' => 'payments'],
            'status' => 'completed',
            'file_path' => 'reports/recent.csv',
            'expires_at' => now()->addWeek(),
            'requested_by' => $this->user->id,
        ]);
        $recent->completed_at = now();
        $recent->save();

        $older = DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'receipts',
            'filters' => ['report_type' => 'receipts'],
            'status' => 'failed',
            'requested_by' => $this->user->id,
        ]);
        $older->created_at = now()->subDays(10);
        $older->save();

        $this->getJson('/api/tenant/donations/reports/exports?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/tenant/donations/reports/exports?report=payments')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.report_type', 'payments')
            ->assertJsonPath('data.data.0.requested_by_name', $this->user->name);

        $this->getJson('/api/tenant/donations/reports/exports?status=failed')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.report_type', 'receipts');

        $this->getJson('/api/tenant/donations/reports/exports?search=ledger')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $recent->id);

        $this->getJson('/api/tenant/donations/reports/exports?date_from='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $recent->id);

        $this->getJson('/api/tenant/donations/reports/exports?sort=created_at&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $older->id);

        $this->getJson('/api/tenant/donations/reports/exports?sort=report&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.report_type', 'payments');

        $this->getJson('/api/tenant/donations/reports/exports?sort=status&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'completed');

        $this->getJson('/api/tenant/donations/reports/exports?sort=requested_by&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    #[Test]
    public function it_reconciles_participation_row_count_with_active_minus_participating(): void
    {
        $giving = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $silent = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $giving->id,
            'payment_number' => 'PAY-PART',
            'payer_name' => 'Giver',
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'participation',
            'preset' => 'last_90',
            'participation_status' => 'not_participating',
        ]));

        $response->assertOk();
        $active = (int) $response->json('data.totals.active_families');
        $participating = (int) $response->json('data.totals.participating_families');
        $notParticipating = (int) $response->json('data.totals.not_participating_families');
        $rowTotal = (int) $response->json('data.pagination.total');

        $this->assertSame(2, $active);
        $this->assertSame(1, $participating);
        $this->assertSame(1, $notParticipating);
        $this->assertSame($notParticipating, $rowTotal);
    }

    #[Test]
    public function it_sorts_participation_preview_by_family_name(): void
    {
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Zulu Family',
        ]);
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Alpha Family',
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'participation',
            'preset' => 'last_90',
            'participation_status' => 'not_participating',
            'sort' => 'family_name',
            'direction' => 'asc',
            'per_page' => 50,
        ]));

        $response->assertOk();
        $names = collect($response->json('data.rows'))->pluck('family_name')->all();
        $sorted = $names;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names);
        $this->assertSame('Alpha Family', $names[0] ?? null);
    }

    #[Test]
    public function it_includes_bcc_code_on_participation_preview_rows(): void
    {
        $bcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_code' => 'SM01',
        ]);
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'bcc_id' => $bcc->id,
            'family_name' => 'BCC Listed Family',
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'participation',
            'preset' => 'last_90',
            'participation_status' => 'not_participating',
            'search' => 'BCC Listed',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.columns.2.key', 'bcc_code');
        $response->assertJsonPath('data.rows.0.bcc_code', 'SM01');
    }

    #[Test]
    public function it_backfills_due_status_changed_at_from_audit_logs(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN2',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Weekly',
            'code' => 'WK',
            'plan_type' => 'uniform',
            'frequency' => 'weekly',
            'default_amount' => 100,
            'status' => 'active',
        ]);
        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'W1',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-07',
            'due_date' => '2026-05-05',
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'waived',
        ]);

        $log = DonationAuditLog::create([
            'tenant_id' => $this->tenant->id,
            'event' => 'due.waived',
            'target_type' => 'due',
            'target_id' => $due->id,
        ]);
        $log->created_at = '2026-05-10 12:00:00';
        $log->save();

        Artisan::call('donations:backfill-due-status-changed');

        $due->refresh();
        $this->assertNotNull($due->status_changed_at);
        $this->assertSame('2026-05-10', $due->status_changed_at->toDateString());
    }

    #[Test]
    public function it_clamps_future_as_of_date_to_parish_today(): void
    {
        $parishToday = DonationBusinessDate::today($this->tenant->id);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'outstanding',
            'as_of_date' => '2099-12-31',
            'view' => 'totals',
        ]));

        $response->assertOk();
        $this->assertSame($parishToday, $response->json('data.totals.as_of'));
    }

    #[Test]
    public function it_returns_too_many_requests_when_user_export_cap_reached(): void
    {
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            DonationReportExport::create([
                'tenant_id' => $this->tenant->id,
                'report_type' => 'payments',
                'filters' => ['report_type' => 'payments', 'date_from' => "2026-02-{$i}"],
                'filter_hash' => 'user-hash-'.$i,
                'status' => 'queued',
                'requested_by' => $this->user->id,
            ]);
        }

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-12-01',
            'date_to' => '2026-12-31',
        ])->assertStatus(429);
    }

    #[Test]
    public function it_sanitizes_formula_payloads_in_payments_csv_export(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FORMULA',
            'payer_name' => '=HYPERLINK("evil")',
            'payment_date' => '2026-06-15',
            'amount' => 50,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertCreated();

        $export = DonationReportExport::query()->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $contents = file_get_contents(storage_path('app/'.$export->file_path));
        $this->assertStringContainsString("'=HYPERLINK", (string) $contents);
    }

    #[Test]
    public function it_reconciles_allocations_preview_total_with_csv_row_count(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $payment = DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-ALLOC',
            'payer_name' => 'Allocator',
            'payment_date' => '2026-06-20',
            'amount' => 300,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'allocatable_type' => 'fund',
            'allocatable_id' => Fund::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'General Fund',
                'code' => 'GF',
                'status' => 'active',
            ])->id,
            'amount' => 300,
        ]);

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'allocations',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $previewTotal = (int) $preview->json('data.pagination.total');

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'allocations',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertCreated();

        $export = DonationReportExport::query()->orderByDesc('created_at')->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $this->assertSame($previewTotal, (int) $export->row_count);
        $this->assertSame('gross', $preview->json('data.meta.amount_basis'));
    }

    #[Test]
    public function it_reconciles_receipts_preview_total_with_csv_row_count(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $payment = DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-RCT',
            'payer_name' => 'Receipt Payer',
            'payment_date' => '2026-06-12',
            'amount' => 120,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationReceipt::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'receipt_number' => 'RCT-100',
            'issued_on' => '2026-06-12',
            'is_void' => false,
            'snapshot' => ['amount' => 120],
        ]);

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'receipts',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $previewTotal = (int) $preview->json('data.pagination.total');

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'receipts',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertCreated();

        $export = DonationReportExport::query()->orderByDesc('created_at')->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $this->assertSame($previewTotal, (int) $export->row_count);
    }

    #[Test]
    public function it_masks_anonymous_payments_in_preview(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-ANON',
            'payer_name' => 'Secret Donor',
            'payment_date' => '2026-06-15',
            'amount' => 75,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
            'is_anonymous' => true,
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $this->assertSame('Anonymous', $response->json('data.rows.0.payer_name'));
        $this->assertSame('Anonymous', $response->json('data.rows.0.family_name'));
    }

    #[Test]
    public function it_rejects_project_id_from_another_tenant(): void
    {
        $other = Tenant::factory()->active()->create();
        $fund = Fund::create([
            'tenant_id' => $other->id,
            'name' => 'Other Fund',
            'code' => 'OTH',
            'status' => 'active',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $other->id,
            'fund_id' => $fund->id,
            'name' => 'Other Project',
            'code' => 'OP',
            'entity_kind' => 'project',
            'assignment_mode' => 'uniform',
            'target_amount' => 1000,
            'default_family_target' => 100,
            'raised_amount' => 0,
            'status' => 'active',
        ]);

        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'preset' => 'this_month',
            'project_id' => $project->id,
        ]))->assertUnprocessable();
    }

    #[Test]
    public function it_includes_inclusive_payment_dates_on_range_boundaries(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        foreach (['2026-06-01', '2026-06-30'] as $index => $date) {
            DonationPayment::create([
                'tenant_id' => $this->tenant->id,
                'family_id' => $family->id,
                'payment_number' => 'PAY-BOUND-'.$index,
                'payer_name' => 'Boundary',
                'payment_date' => $date,
                'amount' => 10,
                'currency' => 'INR',
                'method' => 'cash',
                'status' => 'succeeded',
                'source_type' => 'general',
            ]);
        }

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))->assertOk();

        $this->assertSame(2, (int) $response->json('data.pagination.total'));
    }

    #[Test]
    public function it_aligns_outstanding_overdue_bucket_with_dashboard_overdue_totals(): void
    {
        $asOf = DonationBusinessDate::today($this->tenant->id);
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Overdue Fund',
            'code' => 'OVD',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Plan',
            'code' => 'PLN',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 250,
            'status' => 'active',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'May 2026',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'due_date' => '2026-05-10',
            'amount_due' => 250,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $dashboardOverdue = app(ExecutiveReportMetricsService::class)->overdueAttentionTotals(
            $this->tenant->id,
            $asOf,
            DashboardBccFilter::none(),
            DashboardProjectFilter::none(),
        );

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'outstanding',
            'as_of_date' => $asOf,
            'view' => 'totals',
        ]))->assertOk();

        $rows = $preview->json('data.rows') ?? [];
        $overdueBucket = null;
        foreach ($rows as $row) {
            if (($row['bucket'] ?? '') === 'Overdue') {
                $overdueBucket = (float) $row['amount'];
                break;
            }
        }

        $this->assertNotNull($overdueBucket);
        $this->assertEqualsWithDelta((float) $dashboardOverdue['total_overdue_amount'], $overdueBucket, 0.01);
    }

    #[Test]
    public function it_lists_family_project_funding_gaps_and_reconciles_csv(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Building',
            'code' => 'BLD',
            'status' => 'active',
        ]);
        $project = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Roof Fund',
            'code' => 'ROOF',
            'entity_kind' => 'project',
            'assignment_mode' => 'uniform',
            'target_amount' => 10000,
            'default_family_target' => 1000,
            'raised_amount' => 200,
            'status' => 'active',
        ]);
        ProjectFamilyAssignment::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'target_amount' => 1000,
            'amount_collected' => 200,
            'is_exempt' => false,
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $asOf = DonationBusinessDate::today($this->tenant->id);

        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'project_funding',
            'as_of_date' => $asOf,
            'view' => 'gaps',
        ]))->assertOk();

        $this->assertSame(1, (int) $preview->json('data.pagination.total'));
        $this->assertEqualsWithDelta(800.0, (float) $preview->json('data.rows.0.funding_gap'), 0.01);
        $this->assertSame('gaps', $preview->json('data.meta.view'));

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'project_funding',
            'as_of_date' => $asOf,
            'view' => 'gaps',
        ])->assertCreated();

        $export = DonationReportExport::query()->orderByDesc('created_at')->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $this->assertSame(1, (int) $export->row_count);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function downloadableReportTypesProvider(): array
    {
        $cases = [];
        foreach (DonationReportCatalog::allTypes() as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('downloadableReportTypesProvider')]
    public function it_previews_each_catalog_report_without_error(string $reportType): void
    {
        $query = $this->minimalReportQuery($reportType);
        $definition = DonationReportCatalog::definition($reportType);

        if (! ($definition['preview'] ?? true)) {
            $this->markTestSkipped('Report has no preview.');
        }

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query($query));

        if ($response->status() === 403) {
            $this->markTestSkipped('Tenant lacks entitlement for advanced report.');
        }

        $response->assertOk()->assertJsonPath('success', true);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function catalogReportExportMatrixProvider(): array
    {
        $cases = [];
        foreach (DonationReportCatalog::allTypes() as $reportType) {
            foreach ([DonationReportExportFormat::CSV, DonationReportExportFormat::XLSX, DonationReportExportFormat::PDF] as $format) {
                $cases[$reportType.'_'.$format] = [$reportType, $format];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('catalogReportExportMatrixProvider')]
    public function it_exports_each_catalog_report_with_matching_row_count(string $reportType, string $exportFormat): void
    {
        $definition = DonationReportCatalog::definition($reportType);
        if (! ($definition['export'] ?? true)) {
            $this->markTestSkipped('Report is not exportable.');
        }

        $query = $this->minimalReportQuery($reportType);
        $query['export_format'] = $exportFormat;
        $preview = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query(
            array_diff_key($query, ['export_format' => true]),
        ));
        if ($preview->status() === 403) {
            $this->markTestSkipped('Tenant lacks entitlement for advanced report.');
        }
        $preview->assertOk();
        $previewTotal = (int) $preview->json('data.pagination.total');

        $this->postJson('/api/tenant/donations/reports/export', $query)->assertCreated();
        $export = DonationReportExport::query()->orderByDesc('created_at')->first();
        app(DonationReportExportOrchestrator::class)->processExportRecord($export->id);
        $export->refresh();

        $this->assertSame('completed', $export->status);
        $this->assertSame($exportFormat, $export->export_format);
        $this->assertSame($previewTotal, (int) $export->row_count);
        $this->assertStringEndsWith(
            '.'.DonationReportExportFormat::extension($exportFormat),
            (string) $export->file_path,
        );

        $absolute = storage_path('app/'.$export->file_path);
        $this->assertFileExists($absolute);

        if ($exportFormat === DonationReportExportFormat::PDF) {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($absolute));
        }

        if ($exportFormat === DonationReportExportFormat::XLSX) {
            $sheet = IOFactory::load($absolute)->getActiveSheet();
            $this->assertSame('Report', $sheet->getTitle());
        }

        @unlink($absolute);
    }

    #[Test]
    public function it_rejects_invalid_export_format(): void
    {
        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'preset' => 'this_month',
            'export_format' => 'doc',
        ])->assertUnprocessable();
    }

    #[Test]
    public function it_treats_different_export_formats_as_distinct_requests(): void
    {
        Queue::fake();

        $payload = [
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ];

        $csv = $this->postJson('/api/tenant/donations/reports/export', array_merge($payload, [
            'export_format' => 'csv',
        ]))->assertCreated();

        $xlsx = $this->postJson('/api/tenant/donations/reports/export', array_merge($payload, [
            'export_format' => 'xlsx',
        ]))->assertCreated();

        $this->assertNotSame($csv->json('data.id'), $xlsx->json('data.id'));
        $this->assertSame(2, DonationReportExport::query()->count());
    }

    #[Test]
    public function it_downloads_xlsx_with_correct_content_type(): void
    {
        $directory = storage_path('app/reports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $relative = 'reports/tenant-download-test.xlsx';
        file_put_contents(storage_path('app/'.$relative), 'not-a-real-xlsx');

        $export = DonationReportExport::create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'payments',
            'export_format' => 'xlsx',
            'filters' => ['report_type' => 'payments'],
            'status' => 'completed',
            'file_path' => $relative,
            'expires_at' => now()->addWeek(),
        ]);

        $this->get('/api/tenant/donations/reports/exports/'.$export->id.'/download')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        @unlink(storage_path('app/'.$relative));
    }

    #[Test]
    public function it_writes_audit_logs_for_export_and_download(): void
    {
        Queue::fake();

        $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'payments',
            'preset' => 'this_month',
        ])->assertCreated();

        $this->assertDatabaseHas('donation_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'report.exported',
        ]);

        $export = DonationReportExport::query()->first();
        $relative = 'reports/audit-download-test.csv';
        $absolute = storage_path('app/'.$relative);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }
        file_put_contents($absolute, "header\n");

        $export->update([
            'status' => 'completed',
            'file_path' => $relative,
            'expires_at' => now()->addWeek(),
        ]);

        $this->get('/api/tenant/donations/reports/exports/'.$export->id.'/download')->assertOk();

        $this->assertDatabaseHas('donation_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'report.downloaded',
            'target_id' => $export->id,
        ]);

        @unlink($absolute);
    }

    #[Test]
    public function it_respects_fiscal_year_start_for_this_fy_payment_filter(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');

        DonationSetting::query()->where('tenant_id', $this->tenant->id)->update([
            'financial_year_start_month' => '04',
            'financial_year_start_day' => '01',
            'financial_year_source' => 'tenant',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FY-EDGE',
            'payer_name' => 'FY Edge',
            'payment_date' => '2026-03-31',
            'amount' => 50,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FY-NEW',
            'payer_name' => 'FY New',
            'payment_date' => '2026-04-01',
            'amount' => 60,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'preset' => 'this_fy',
        ]))->assertOk();

        $numbers = collect($response->json('data.rows'))->pluck('payment_number')->all();
        $this->assertContains('PAY-FY-NEW', $numbers);
        $this->assertNotContains('PAY-FY-EDGE', $numbers);

        Carbon::setTestNow();
    }

    #[Test]
    public function each_catalog_report_preview_includes_a_dataset_footer(): void
    {
        $types = array_keys(DonationReportCatalog::definitions());
        $types = array_values(array_filter(
            $types,
            fn (string $type) => $type !== DonationReportCatalog::TYPE_PARISH_COMPARISON,
        ));

        foreach ($types as $reportType) {
            $response = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query(
                $this->minimalReportQuery($reportType),
            ));

            $response->assertOk();
            $footer = $response->json('data.footer');
            $this->assertIsArray($footer, $reportType.' is missing a footer');
            $this->assertNotSame([], $footer, $reportType.' footer is empty');
        }
    }

    #[Test]
    public function family_giving_filters_honor_fiscal_year_community_and_search(): void
    {
        $bcc = BCC::factory()->active()->create(['tenant_id' => $this->tenant->id, 'name' => 'St Mary BCC']);
        $inCommunity = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Alpha Household',
            'family_code' => 'FAM-ALPHA',
            'bcc_id' => $bcc->id,
        ]);
        $other = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Beta Household',
            'family_code' => 'FAM-BETA',
            'bcc_id' => null,
        ]);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $inCommunity->id,
            'payment_number' => 'PAY-FY-OLD',
            'payer_name' => 'Alpha Payer',
            'payment_date' => '2025-06-15',
            'amount' => 80,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $other->id,
            'payment_number' => 'PAY-FY-NEW',
            'payer_name' => 'Beta Payer',
            'payment_date' => '2026-06-15',
            'amount' => 120,
            'currency' => 'INR',
            'method' => 'cheque',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $byYear = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'family_giving',
            'fiscal_year' => '2025-26',
        ]))->assertOk();
        $yearNames = collect($byYear->json('data.rows'))->pluck('family_name')->all();
        $this->assertSame(['Alpha Household'], $yearNames);
        $this->assertEquals(80, (float) $byYear->json('data.footer.collected_total'));

        $bySearch = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'family_giving',
            'fiscal_year' => '2026',
            'search' => 'Beta',
        ]))->assertOk();
        $this->assertSame(['Beta Household'], collect($bySearch->json('data.rows'))->pluck('family_name')->all());

        $byBcc = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'family_giving',
            'fiscal_year' => '2025-26',
            'bcc_id' => 'unassigned',
        ]))->assertOk();
        $this->assertSame([], $byBcc->json('data.rows'));

        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'family_giving',
            'fiscal_year' => 'not-a-year',
        ]))->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    #[Test]
    public function payment_filters_honor_method_status_and_search(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Searchable Family',
            'family_code' => 'FAM-SRCH',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-CASH',
            'payer_name' => 'Cash Payer',
            'payment_date' => '2026-06-10',
            'amount' => 40,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-UPI',
            'payer_name' => 'Cheque Payer',
            'payment_date' => '2026-06-11',
            'amount' => 60,
            'currency' => 'INR',
            'method' => 'cheque',
            'status' => 'refunded',
            'source_type' => 'general',
        ]);

        $cash = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'method' => 'cash',
        ]))->assertOk();
        $this->assertSame(['PAY-CASH'], collect($cash->json('data.rows'))->pluck('payment_number')->all());

        $refunded = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'status' => 'refunded',
        ]))->assertOk();
        $this->assertSame(['PAY-UPI'], collect($refunded->json('data.rows'))->pluck('payment_number')->all());

        $search = $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query([
            'report_type' => 'payments',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'search' => 'Searchable',
        ]))->assertOk();
        $this->assertCount(2, $search->json('data.rows'));
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalReportQuery(string $reportType): array
    {
        $asOf = DonationBusinessDate::today($this->tenant->id);

        return match ($reportType) {
            DonationReportCatalog::TYPE_PAYMENTS => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_DONATION_ENTRIES => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_RECEIPTS => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_OUTSTANDING => ['report_type' => $reportType, 'as_of_date' => $asOf, 'view' => 'families'],
            DonationReportCatalog::TYPE_PARTICIPATION => ['report_type' => $reportType, 'preset' => 'last_90'],
            DonationReportCatalog::TYPE_DUES_BY_PLAN => [
                'report_type' => $reportType,
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ],
            DonationReportCatalog::TYPE_PROJECT_FUNDING => ['report_type' => $reportType, 'as_of_date' => $asOf],
            DonationReportCatalog::TYPE_FAMILY_GIVING => ['report_type' => $reportType],
            DonationReportCatalog::TYPE_ALLOCATIONS => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_ADJUSTMENTS => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_DISBURSEMENTS => ['report_type' => $reportType, 'preset' => 'this_month'],
            DonationReportCatalog::TYPE_COLLECTIONS_BY_MONTH => ['report_type' => $reportType, 'preset' => 'this_fy'],
            DonationReportCatalog::TYPE_PARISH_COMPARISON => ['report_type' => $reportType],
            default => ['report_type' => $reportType],
        };
    }
}
