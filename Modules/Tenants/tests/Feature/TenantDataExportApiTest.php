<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Export\TenantDataExportContributorRegistry;
use Modules\Tenants\Export\TenantDataExportMediaCopier;
use Modules\Tenants\Jobs\ProcessTenantDataExportJob;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Services\TenantDataExportAuditService;
use Modules\Tenants\Services\TenantDataExportOrchestrator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Tenant Data Export — functional, security, retention, and media coverage (Phases 4–7).
 *
 * Manual smoke (Settings → Data Export):
 * 1. Assign tenant.data.export to Tenant Admin; open Settings → Data Export.
 * 2. Start export with Users & roles selected; confirm history row appears.
 * 3. With queue worker running (`tenant-exports,default`), wait for Completed.
 * 4. Download ZIP; open data/users.csv — no password/remember_token columns.
 * 5. Confirm cancel works only while Queued; retry works from Failed.
 * 6. Optional: toggle Photos & logos; confirm documents/ only when enabled.
 *
 * Ops checklist: docs/platform/tenant-data-export-ops.md
 */
class TenantDataExportApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $tenantAdminUser;

    private User $otherTenantUser;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = storage_path('app/private-testing-'.uniqid('', true));

        config([
            'tenants.export.disk' => 'local',
            'tenants.export.queue' => 'sync',
            'tenants.export.chunk_size' => 100,
            'tenants.export.retention_days' => 7,
            'tenants.export.max_active_per_tenant' => 1,
            'tenants.export.max_concurrent_global' => 2,
            'filesystems.disks.local.root' => $this->diskRoot,
        ]);

        Storage::disk('local')->makeDirectory('/');

        $this->tenant = Tenant::factory()->create(['active' => 1]);
        $this->otherTenant = Tenant::factory()->create(['active' => 1]);

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permission = Permission::updateOrCreate(
            ['name' => 'tenant.data.export'],
            [
                'display_name' => 'Export Tenant Data',
                'description' => 'Test permission',
                'module' => 'TenantDataExport',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'settings',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->tenantAdminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'name' => 'Parish Admin',
            'email' => 'admin@parish.test',
            'password' => bcrypt('secret-should-not-export'),
            'active' => 1,
        ]);
        $this->tenantAdminUser->syncRoles([$role->id]);

        $otherRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Other Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->otherTenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $otherRole->permissions()->syncWithoutDetaching([$permission->id]);

        $this->otherTenantUser = User::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'role_id' => $otherRole->id,
            'name' => 'Other Parish Admin',
            'email' => 'other-tenant@parish.test',
            'active' => 1,
        ]);
        $this->otherTenantUser->syncRoles([$otherRole->id]);

        Passport::actingAs($this->tenantAdminUser);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->diskRoot)) {
            $this->deleteDirectory($this->diskRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_lists_export_modules_including_users(): void
    {
        $response = $this->getJson('/api/tenant/export/modules');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertContains('users', $keys);
        foreach (['families', 'bcc', 'ministries', 'sacraments', 'church_profile', 'donations'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    #[Test]
    public function it_exports_multiple_modules_including_families(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Slice Verification Family',
            'created_by' => $this->tenantAdminUser->id,
            'updated_by' => $this->tenantAdminUser->id,
        ]);

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Maria',
            'last_name' => 'Verification',
            'created_by' => $this->tenantAdminUser->id,
            'updated_by' => $this->tenantAdminUser->id,
        ]);

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users', 'families'],
        ]);

        $create->assertCreated();
        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $absolute = Storage::disk('local')->path((string) $export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);

        $this->assertNotFalse($zip->getFromName('data/users.csv'));
        $this->assertNotFalse($zip->getFromName('data/roles.csv'));
        $familiesCsv = $zip->getFromName('data/families.csv');
        $membersCsv = $zip->getFromName('data/members.csv');
        $zonesCsv = $zip->getFromName('data/parish_zones.csv');
        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        $this->assertNotFalse($familiesCsv);
        $this->assertNotFalse($membersCsv);
        $this->assertFalse($zonesCsv);
        $this->assertStringContainsString('Slice Verification Family', (string) $familiesCsv);
        $this->assertStringContainsString((string) $family->id, (string) $familiesCsv);
        $this->assertStringContainsString((string) $family->id, (string) $membersCsv);
        $this->assertStringContainsString('Maria', (string) $membersCsv);
        $this->assertStringContainsString((string) $member->id, (string) $membersCsv);
        $this->assertStringContainsString('"modules"', (string) $manifest);
        $this->assertArrayHasKey('data/families.csv', $export->record_counts ?? []);
        $this->assertArrayHasKey('data/members.csv', $export->record_counts ?? []);
    }

    #[Test]
    public function it_exports_all_structured_modules_when_empty(): void
    {
        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => [
                'users',
                'families',
                'bcc',
                'ministries',
                'sacraments',
                'church_profile',
                'donations',
            ],
        ]);

        $create->assertCreated();
        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $absolute = Storage::disk('local')->path((string) $export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);

        foreach ([
            'data/users.csv',
            'data/families.csv',
            'data/members.csv',
            'data/bccs.csv',
            'data/bcc_leaders.csv',
            'data/ministry_organizations.csv',
            'data/sacraments.csv',
            'data/church_profile.csv',
            'data/donation_categories.csv',
            'data/donations.csv',
            'manifest.json',
        ] as $member) {
            $this->assertNotFalse($zip->getFromName($member), "Missing ZIP member: {$member}");
        }

        $zip->close();
    }

    #[Test]
    public function it_omits_documents_when_media_is_off(): void
    {
        Storage::fake('public');
        $logoPath = 'tenants/'.$this->tenant->id.'/logos/logo_test.png';
        Storage::disk('public')->put($logoPath, 'fake-logo-bytes');
        $this->tenant->update(['logo_url' => $logoPath]);

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
            'include_media' => false,
        ]);

        $create->assertCreated();
        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $this->assertFalse((bool) ($export->options['include_media'] ?? true));

        $absolute = Storage::disk('local')->path((string) $export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) ($manifest['include_media'] ?? true));
        $this->assertSame([], $manifest['media_files'] ?? ['missing']);
        $this->assertFalse($zip->getFromName('documents/'.$logoPath));
        $zip->close();
    }

    #[Test]
    public function it_includes_authorized_media_and_skips_cross_tenant_paths(): void
    {
        Storage::fake('public');

        $ownLogo = 'tenants/'.$this->tenant->id.'/logos/logo_own.png';
        $ownFamilyImage = 'families/'.$this->tenant->id.'/profiles/profile_own.jpg';
        $ownLeadership = 'tenants/'.$this->tenant->id.'/leadership/leader_t'.$this->tenant->id.'_1_photo.jpg';
        $crossLogo = 'tenants/'.$this->otherTenant->id.'/logos/logo_other.png';
        $popePath = 'popes/1/photo.jpg';

        Storage::disk('public')->put($ownLogo, 'own-logo');
        Storage::disk('public')->put($ownFamilyImage, 'own-family');
        Storage::disk('public')->put($ownLeadership, 'own-leader');
        Storage::disk('public')->put($crossLogo, 'cross-logo');
        Storage::disk('public')->put($popePath, 'pope-photo');

        $this->tenant->update(['logo_url' => $ownLogo]);

        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Media Family',
            'profile_image_url' => $ownFamilyImage,
            'head_profile_image_url' => $crossLogo, // wrong-tenant path must be skipped
            'created_by' => $this->tenantAdminUser->id,
            'updated_by' => $this->tenantAdminUser->id,
        ]);

        ChurchLeadership::query()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Authorized Leader',
            'role' => 'pastor',
            'photo_url' => $ownLeadership,
            'active' => 1,
            'is_primary' => true,
            'display_order' => 1,
        ]);

        ChurchLeadership::query()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Leader With Bad Photo',
            'role' => 'associate',
            'photo_url' => $popePath,
            'active' => 1,
            'is_primary' => false,
            'display_order' => 2,
        ]);

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users', 'families', 'church_profile'],
            'include_media' => true,
        ]);

        $create->assertCreated();
        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $this->assertTrue((bool) ($export->options['include_media'] ?? false));

        $absolute = Storage::disk('local')->path((string) $export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);

        $this->assertNotFalse($zip->getFromName('documents/'.$ownLogo));
        $this->assertNotFalse($zip->getFromName('documents/'.$ownFamilyImage));
        $this->assertNotFalse($zip->getFromName('documents/'.$ownLeadership));
        $this->assertFalse($zip->getFromName('documents/'.$crossLogo));
        $this->assertFalse($zip->getFromName('documents/'.$popePath));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();

        $this->assertTrue((bool) $manifest['include_media']);
        $this->assertContains('documents/'.$ownLogo, $manifest['media_files']);
        $this->assertContains('documents/'.$ownFamilyImage, $manifest['media_files']);
        $this->assertContains('documents/'.$ownLeadership, $manifest['media_files']);
        $this->assertNotContains('documents/'.$crossLogo, $manifest['media_files']);
        $this->assertNotContains('documents/'.$popePath, $manifest['media_files']);
        $this->assertNotEmpty($manifest['warnings']);
        $this->assertSame(3, (int) ($export->record_counts['documents'] ?? 0));
    }

    #[Test]
    public function it_exports_users_module_to_downloadable_zip_without_secrets(): void
    {
        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true);

        $exportId = $create->json('data.id');
        $this->assertNotEmpty($exportId);

        $export = $this->assertExportFinished((string) $exportId);
        $this->assertTrue($export->isDownloadable());

        $absolute = Storage::disk('local')->path($export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $usersCsv = $zip->getFromName('data/users.csv');
        $rolesCsv = $zip->getFromName('data/roles.csv');
        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        $this->assertNotFalse($usersCsv);
        $this->assertNotFalse($rolesCsv);
        $this->assertNotFalse($manifest);
        $this->assertStringContainsString('Parish Admin', $usersCsv);
        $this->assertStringContainsString('admin@parish.test', $usersCsv);
        $this->assertStringNotContainsString('password', strtolower($usersCsv));
        $this->assertStringNotContainsString('remember_token', strtolower($usersCsv));
        $this->assertStringNotContainsString('secret-should-not-export', $usersCsv);
        $this->assertStringContainsString('"export_id": "'.$exportId.'"', $manifest);

        $this->assertDatabaseHas('tenant_data_export_audits', [
            'tenant_id' => $this->tenant->id,
            'target_id' => $exportId,
            'event' => TenantDataExportAuditService::EVENT_COMPLETED,
        ]);

        $download = $this->get('/api/tenant/export/bulk/'.$exportId.'/download');
        $download->assertOk();
        $this->assertStringContainsString('application/zip', (string) $download->headers->get('Content-Type'));

        $this->assertDatabaseHas('tenant_data_export_audits', [
            'tenant_id' => $this->tenant->id,
            'target_id' => $exportId,
            'event' => TenantDataExportAuditService::EVENT_DOWNLOADED,
        ]);
    }

    #[Test]
    public function it_matches_manifest_record_counts_to_csv_rows(): void
    {
        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);
        $create->assertCreated();

        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $absolute = Storage::disk('local')->path((string) $export->file_path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $usersCsv = (string) $zip->getFromName('data/users.csv');
        $rolesCsv = (string) $zip->getFromName('data/roles.csv');
        $manifestJson = (string) $zip->getFromName('manifest.json');
        $zip->close();

        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($export->id, $manifest['export_id']);
        $this->assertSame((int) $this->tenant->id, (int) $manifest['tenant_id']);
        $this->assertSame(
            $this->countCsvDataRows($usersCsv),
            (int) ($manifest['record_counts']['data/users.csv'] ?? -1)
        );
        $this->assertSame(
            $this->countCsvDataRows($rolesCsv),
            (int) ($manifest['record_counts']['data/roles.csv'] ?? -1)
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $usersCsv);
    }

    #[Test]
    public function it_scopes_export_to_export_tenant_and_excludes_soft_deleted_users(): void
    {
        $activeMember = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Active Member',
            'email' => 'active-member@parish.test',
            'active' => 1,
        ]);
        $deletedMember = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deleted Member',
            'email' => 'deleted-member@parish.test',
            'active' => 1,
        ]);
        $deletedMember->delete();

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);
        $create->assertCreated();

        $export = $this->assertExportFinished((string) $create->json('data.id'));
        $absolute = Storage::disk('local')->path((string) $export->file_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $usersCsv = (string) $zip->getFromName('data/users.csv');
        $zip->close();

        $this->assertStringContainsString($activeMember->email, $usersCsv);
        $this->assertStringNotContainsString('deleted-member@parish.test', $usersCsv);
        $this->assertStringNotContainsString('other-tenant@parish.test', $usersCsv);
        $this->assertSame((int) $this->tenant->id, (int) $export->tenant_id);
    }

    #[Test]
    public function it_cancels_queued_export_and_rejects_cancelling_processing(): void
    {
        Queue::fake();

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.status', TenantDataExport::STATUS_QUEUED);
        $exportId = $create->json('data.id');

        Queue::assertPushed(ProcessTenantDataExportJob::class);

        $cancel = $this->postJson('/api/tenant/export/bulk/'.$exportId.'/cancel');
        $cancel->assertOk()
            ->assertJsonPath('data.status', TenantDataExport::STATUS_CANCELLED);

        $this->assertDatabaseHas('tenant_data_export_audits', [
            'target_id' => $exportId,
            'event' => TenantDataExportAuditService::EVENT_CANCELLED,
        ]);

        $processing = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_PROCESSING,
            'progress' => ['modules' => ['users' => ['status' => 'processing', 'records' => 0]], 'approx_percent' => 0],
        ]);

        $this->postJson('/api/tenant/export/bulk/'.$processing->id.'/cancel')
            ->assertStatus(409);
    }

    #[Test]
    public function it_retries_failed_export_as_new_row(): void
    {
        $failed = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_FAILED,
            'error_message' => 'Export failed while preparing your data. Please try again.',
            'progress' => ['modules' => ['users' => ['status' => 'failed', 'records' => 0]], 'approx_percent' => 0],
        ]);

        $retry = $this->postJson('/api/tenant/export/bulk/'.$failed->id.'/retry');
        $retry->assertCreated();

        $newId = $retry->json('data.id');
        $this->assertNotSame($failed->id, $newId);
        $this->assertSame(TenantDataExport::STATUS_FAILED, $failed->fresh()->status);
        $this->assertTrue($this->assertExportFinished((string) $newId)->isDownloadable());
    }

    #[Test]
    public function it_blocks_download_for_expired_failed_and_cancelled_exports(): void
    {
        $expired = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->tenant->id.'/expired.zip',
            'file_size' => 10,
            'expires_at' => now()->subDay(),
            'completed_at' => now()->subDays(8),
        ]);
        Storage::disk('local')->put((string) $expired->file_path, 'fake-zip');

        $this->getJson('/api/tenant/export/bulk/'.$expired->id.'/download')
            ->assertStatus(409);

        $failed = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_FAILED,
            'error_message' => 'boom',
        ]);
        $this->getJson('/api/tenant/export/bulk/'.$failed->id.'/download')
            ->assertStatus(409);

        $cancelled = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_CANCELLED,
        ]);
        $this->getJson('/api/tenant/export/bulk/'.$cancelled->id.'/download')
            ->assertStatus(409);
    }

    #[Test]
    public function it_rejects_cross_tenant_download(): void
    {
        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);
        $create->assertCreated();
        $exportId = $create->json('data.id');

        Passport::actingAs($this->otherTenantUser);

        $this->getJson('/api/tenant/export/bulk/'.$exportId)
            ->assertNotFound();

        $this->getJson('/api/tenant/export/bulk/'.$exportId.'/download')
            ->assertNotFound();
    }

    #[Test]
    public function it_rejects_second_active_export_for_same_tenant(): void
    {
        TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_PROCESSING,
            'progress' => ['modules' => ['users' => ['status' => 'processing', 'records' => 0]], 'approx_percent' => 0],
        ]);

        $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ])->assertStatus(409);
    }

    #[Test]
    public function it_forbids_users_without_permission(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'active' => 1,
        ]);
        Passport::actingAs($user);

        $this->getJson('/api/tenant/export/modules')->assertForbidden();
        $this->postJson('/api/tenant/export/bulk', ['modules' => ['users']])->assertForbidden();
        $this->getJson('/api/tenant/export/bulk')->assertForbidden();
    }

    #[Test]
    public function cleanup_command_expires_completed_exports_past_retention(): void
    {
        $path = 'exports/'.$this->tenant->id.'/old-export.zip';
        Storage::disk('local')->put($path, 'zip-bytes');

        $export = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_COMPLETED,
            'file_path' => $path,
            'file_size' => 9,
            'expires_at' => now()->subMinute(),
            'completed_at' => now()->subDays(8),
        ]);

        $this->artisan('tenants:cleanup-exports')->assertSuccessful();

        $export->refresh();
        $this->assertSame(TenantDataExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->file_path);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDatabaseHas('tenant_data_export_audits', [
            'target_id' => $export->id,
            'event' => TenantDataExportAuditService::EVENT_EXPIRED,
        ]);
    }

    #[Test]
    public function cleanup_dry_run_does_not_expire_or_delete_files(): void
    {
        $path = 'exports/'.$this->tenant->id.'/dry-run-export.zip';
        Storage::disk('local')->put($path, 'zip-bytes');

        $export = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_COMPLETED,
            'file_path' => $path,
            'file_size' => 9,
            'expires_at' => now()->subMinute(),
            'completed_at' => now()->subDays(8),
        ]);

        $this->artisan('tenants:cleanup-exports', ['--dry-run' => true])->assertSuccessful();

        $export->refresh();
        $this->assertSame(TenantDataExport::STATUS_COMPLETED, $export->status);
        $this->assertSame($path, $export->file_path);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    #[Test]
    public function it_stores_safe_error_message_when_parish_becomes_unavailable(): void
    {
        Queue::fake();

        $create = $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users'],
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.status', TenantDataExport::STATUS_QUEUED);
        $exportId = $create->json('data.id');

        $this->tenant->update(['active' => 0]);

        $job = new ProcessTenantDataExportJob(
            (int) $this->tenant->id,
            (int) $this->tenantAdminUser->id,
            (string) $exportId,
        );
        $job->handle();

        $export = TenantDataExport::findOrFail($exportId);
        $this->assertSame(TenantDataExport::STATUS_FAILED, $export->status);
        $this->assertSame(
            'This parish is inactive or unavailable. Export cannot continue.',
            $export->error_message
        );
        $this->assertStringNotContainsString('SQLSTATE', (string) $export->error_message);
        $this->assertStringNotContainsString('Stack trace', (string) $export->error_message);
        $this->assertStringNotContainsString('/var/www', (string) $export->error_message);
    }

    #[Test]
    public function it_stores_safe_error_message_on_unexpected_processing_failure(): void
    {
        $export = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_QUEUED,
            'progress' => ['modules' => ['users' => ['status' => 'queued', 'records' => 0]], 'approx_percent' => 0],
        ]);

        $registry = \Mockery::mock(TenantDataExportContributorRegistry::class);
        $registry->shouldReceive('get')
            ->once()
            ->with('users')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: secret stack at /var/www/html/EkklesiaSoft/secret.php:99'));

        $orchestrator = new TenantDataExportOrchestrator(
            $registry,
            app(TenantDataExportAuditService::class),
            app(TenantDataExportMediaCopier::class)
        );

        $result = $orchestrator->process((string) $export->id);

        $this->assertSame(TenantDataExport::STATUS_FAILED, $result->status);
        $this->assertSame(
            'Export failed while preparing your data. Please try again.',
            $result->error_message
        );
        $this->assertStringNotContainsString('SQLSTATE', (string) $result->error_message);
        $this->assertStringNotContainsString('/var/www', (string) $result->error_message);
        $this->assertStringNotContainsString('secret.php', (string) $result->error_message);
    }

    #[Test]
    public function it_lists_export_history_for_current_tenant_only(): void
    {
        $own = TenantDataExport::create([
            'tenant_id' => $this->tenant->id,
            'requested_by' => $this->tenantAdminUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        TenantDataExport::create([
            'tenant_id' => $this->otherTenant->id,
            'requested_by' => $this->otherTenantUser->id,
            'modules' => ['users'],
            'options' => ['include_media' => false, 'format' => 'csv'],
            'status' => TenantDataExport::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $response = $this->getJson('/api/tenant/export/bulk');
        $response->assertOk();

        $payload = $response->json('data');
        $rows = $payload['data'] ?? $payload;
        $this->assertIsArray($rows);
        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        foreach ($ids as $id) {
            $row = TenantDataExport::findOrFail($id);
            $this->assertSame((int) $this->tenant->id, (int) $row->tenant_id);
        }
    }

    #[Test]
    public function it_rejects_unknown_export_modules(): void
    {
        $this->postJson('/api/tenant/export/bulk', [
            'modules' => ['users', 'not_a_real_module'],
        ])->assertStatus(422);
    }

    private function assertExportFinished(string $exportId): TenantDataExport
    {
        $export = TenantDataExport::findOrFail($exportId);
        $this->assertSame(
            TenantDataExport::STATUS_COMPLETED,
            $export->status,
            (string) $export->error_message
        );
        $this->assertNotEmpty($export->file_path);
        $this->assertTrue(Storage::disk('local')->exists((string) $export->file_path));

        return $export;
    }

    private function countCsvDataRows(string $csv): int
    {
        $csv = preg_replace("/^\xEF\xBB\xBF/", '', $csv) ?? $csv;
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, static fn ($line) => $line !== ''));

        return max(0, count($lines) - 1);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
