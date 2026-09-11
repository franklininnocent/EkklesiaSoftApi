<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-photo
 */
class BishopPhotoApiTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected User $ekklesiaUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        if (! Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'status' => 'active',
            ]);
        }

        if (! Country::where('iso2', 'IN')->exists()) {
            Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'status' => 'active',
            ]);
        }

        $this->diocese = DioceseManagement::factory()->create();
        $this->ekklesiaUser = $this->createEkklesiaAdmin();
        Passport::actingAs($this->ekklesiaUser);
    }

    #[Test]
    public function it_deletes_bishop_photo(): void
    {
        $bishop = $this->createBishop('Bishop David');
        $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('david.jpg', 200, 200),
        ])->assertOk();

        $this->deleteJson("/api/ecclesiastical/bishops/{$bishop->id}/photo")
            ->assertOk()
            ->assertJsonPath('success', true);

        $bishop->refresh();
        $this->assertNull($bishop->photo_path);
        $this->assertNull($bishop->photo_url);
    }

    #[Test]
    public function it_returns_signed_photo_url_on_bishop_resource(): void
    {
        $bishop = $this->createBishop('Bishop David');
        $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('david.jpg', 200, 200),
        ])->assertOk();

        $bishop->refresh();
        $this->assertStringContainsString('platform/bishops/', $bishop->photo_path);
        Storage::disk('local')->assertExists($bishop->photo_path);

        $response = $this->getJson("/api/ecclesiastical/bishops/{$bishop->id}");

        $response->assertOk()
            ->assertJsonPath('data.has_photo', true)
            ->assertJsonPath('data.photo_public_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonMissingPath('data.photo_path')
            ->assertJsonMissingPath('data.photo_url');
    }

    #[Test]
    public function it_returns_resolved_photo_on_diocese_leadership(): void
    {
        $bishop = $this->createOrdinary('Bishop David', '2024-01-01');
        $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('david.jpg', 200, 200),
        ])->assertOk();

        $response = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership");

        $response->assertOk()
            ->assertJsonPath('data.ordinary.has_photo', true)
            ->assertJsonPath('data.ordinary.photo_public_url', fn ($url) => is_string($url) && $url !== '');
    }

    #[Test]
    public function it_preserves_distinct_bishop_photos_after_succession(): void
    {
        $john = $this->createOrdinary('Bishop John', '2018-01-01');
        $this->postJson("/api/ecclesiastical/bishops/{$john->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('john.jpg', 200, 200),
        ])->assertOk();
        $johnPhotoPath = $john->fresh()->photo_path;

        $david = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop David',
            'status' => 'active',
        ], (int) $this->ekklesiaUser->id, true);

        $this->postJson("/api/ecclesiastical/bishops/{$david->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('david.jpg', 200, 200),
        ])->assertOk();

        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $david,
            ['effective_date' => '2025-01-01'],
        );

        $leadership = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership");
        $leadership->assertOk()
            ->assertJsonPath('data.ordinary.bishop_id', $david->id)
            ->assertJsonPath('data.ordinary.has_photo', true);

        $john->refresh();
        $this->assertSame($johnPhotoPath, $john->photo_path);
        $this->assertNotSame($david->fresh()->photo_path, $john->photo_path);
    }

    #[Test]
    public function it_rejects_invalid_upload_types(): void
    {
        $bishop = $this->createBishop('Bishop Peter');

        $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->create('notes.txt', 100, 'text/plain'),
        ])->assertStatus(422);

        $this->assertNull($bishop->fresh()->photo_path);
    }

    #[Test]
    public function it_rejects_oversized_uploads(): void
    {
        $bishop = $this->createBishop('Bishop Peter');

        $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('large.jpg')->size(4000),
        ])->assertStatus(422);

        $this->assertNull($bishop->fresh()->photo_path);
    }

    #[Test]
    public function it_rejects_client_photo_url_on_bishop_create(): void
    {
        $this->postJson('/api/ecclesiastical/bishops', [
            'full_name' => 'Bishop Client URL',
            'photo_url' => 'https://cdn.example.com/bishop.jpg',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    #[Test]
    public function it_rejects_client_photo_url_on_bishop_update(): void
    {
        $bishop = $this->createBishop('Bishop Secure');

        $this->putJson("/api/ecclesiastical/bishops/{$bishop->id}", [
            'photo_url' => 'https://cdn.example.com/bishop.jpg',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    #[Test]
    public function bishop_file_upload_service_validates_image_integrity(): void
    {
        $service = app(BishopFileUploadService::class);
        $file = UploadedFile::fake()->create('fake.jpg', 100, 'image/jpeg');

        $result = $service->validateFile($file);

        $this->assertFalse($result['valid']);
    }

    private function createEkklesiaAdmin(): User
    {
        $permissions = [
            'bishops.view', 'bishops.create', 'bishops.update', 'bishops.archive',
            'bishops.manage_appointments', 'bishops.manage_images', 'bishops.view_audit',
            'dioceses.view', 'dioceses.create', 'dioceses.update', 'dioceses.delete',
        ];

        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
            ]
        );

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName, 'tenant_id' => null],
                ['display_name' => $permissionName, 'module' => 'EcclesiasticalData']
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['tenant_id' => null]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function createBishop(string $name): BishopManagement
    {
        return app(BishopService::class)->createPerson([
            'full_name' => $name,
            'status' => 'active',
        ], (int) $this->ekklesiaUser->id, true);
    }

    private function createOrdinary(string $name, string $effectiveDate): BishopManagement
    {
        $bishop = app(BishopService::class)->createPerson([
            'full_name' => $name,
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], (int) $this->ekklesiaUser->id, true);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => $effectiveDate],
        );

        return $bishop->fresh();
    }
}
