<?php

namespace Modules\Sacraments\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use App\Models\User;

class SacramentModelTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected SacramentType $sacramentType;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->sacramentType = SacramentType::factory()->create();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        // Arrange
        $fillable = [
            'tenant_id', 'sacrament_type_id', 'recipient_name', 'date_administered',
            'place_administered', 'minister_name', 'minister_title', 'certificate_number',
            'book_number', 'page_number', 'recipient_birth_date', 'recipient_birth_place',
            'father_name', 'mother_name', 'godparent1_name', 'godparent2_name',
            'witnesses', 'notes', 'document_path', 'status', 'conditional_date',
            'conditional_reason', 'created_by', 'updated_by',
        ];

        // Act
        $model = new Sacrament();

        // Assert
        $this->assertEquals($fillable, $model->getFillable());
    }

    /** @test */
    public function it_has_correct_casts()
    {
        // Arrange & Act
        $model = new Sacrament();
        $casts = $model->getCasts();

        // Assert
        $this->assertEquals('date', $casts['date_administered']);
        $this->assertEquals('date', $casts['recipient_birth_date']);
        $this->assertEquals('date', $casts['conditional_date']);
        $this->assertEquals('datetime', $casts['created_at']);
        $this->assertEquals('datetime', $casts['updated_at']);
        $this->assertEquals('datetime', $casts['deleted_at']);
    }

    /** @test */
    public function it_belongs_to_tenant()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $tenant = $sacrament->tenant;

        // Assert
        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertEquals($this->tenant->id, $tenant->id);
    }

    /** @test */
    public function it_belongs_to_sacrament_type()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $type = $sacrament->sacramentType;

        // Assert
        $this->assertInstanceOf(SacramentType::class, $type);
        $this->assertEquals($this->sacramentType->id, $type->id);
    }

    /** @test */
    public function it_belongs_to_creator()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'created_by' => $this->user->id,
        ]);

        // Act
        $creator = $sacrament->creator;

        // Assert
        $this->assertInstanceOf(User::class, $creator);
        $this->assertEquals($this->user->id, $creator->id);
    }

    /** @test */
    public function it_belongs_to_updater()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'updated_by' => $this->user->id,
        ]);

        // Act
        $updater = $sacrament->updater;

        // Assert
        $this->assertInstanceOf(User::class, $updater);
        $this->assertEquals($this->user->id, $updater->id);
    }

    /** @test */
    public function it_can_scope_by_tenant()
    {
        // Arrange
        $otherTenant = Tenant::factory()->create();
        
        Sacrament::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);
        
        Sacrament::factory()->count(2)->create([
            'tenant_id' => $otherTenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = Sacrament::forTenant($this->tenant->id)->get();

        // Assert
        $this->assertEquals(3, $result->count());
    }

    /** @test */
    public function it_can_scope_by_sacrament_type()
    {
        // Arrange
        $otherType = SacramentType::factory()->create();
        
        Sacrament::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);
        
        Sacrament::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $otherType->id,
        ]);

        // Act
        $result = Sacrament::bySacramentType($this->sacramentType->id)->get();

        // Assert
        $this->assertEquals(3, $result->count());
    }

    /** @test */
    public function it_can_scope_by_status()
    {
        // Arrange
        Sacrament::factory()->count(3)->active()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);
        
        Sacrament::factory()->count(2)->cancelled()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = Sacrament::byStatus('active')->get();

        // Assert
        $this->assertEquals(3, $result->count());
    }

    /** @test */
    public function it_can_search_by_recipient_name()
    {
        // Arrange
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'John Doe',
        ]);
        
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'Jane Smith',
        ]);

        // Act
        $result = Sacrament::searchRecipient('John')->get();

        // Assert
        $this->assertEquals(1, $result->count());
        $this->assertStringContainsString('John', $result->first()->recipient_name);
    }

    /** @test */
    public function it_can_filter_by_date_range()
    {
        // Arrange
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2025-06-15',
        ]);
        
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2024-01-10',
        ]);

        // Act
        $result = Sacrament::dateRange('2025-01-01', '2025-12-31')->get();

        // Assert
        $this->assertEquals(1, $result->count());
    }

    /** @test */
    public function it_can_check_if_sacrament_is_active()
    {
        // Arrange
        $activeSacrament = Sacrament::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);
        
        $cancelledSacrament = Sacrament::factory()->cancelled()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act & Assert
        $this->assertTrue($activeSacrament->isActive());
        $this->assertFalse($cancelledSacrament->isActive());
    }

    /** @test */
    public function it_generates_certificate_reference_attribute()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'book_number' => 'BOOK-123',
            'page_number' => '456',
            'certificate_number' => 'CERT-789',
        ]);

        // Act
        $reference = $sacrament->certificate_reference;

        // Assert
        $this->assertStringContainsString('Book: BOOK-123', $reference);
        $this->assertStringContainsString('Page: 456', $reference);
        $this->assertStringContainsString('Cert: CERT-789', $reference);
    }

    /** @test */
    public function it_returns_no_reference_when_all_fields_empty()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'book_number' => null,
            'page_number' => null,
            'certificate_number' => null,
        ]);

        // Act
        $reference = $sacrament->certificate_reference;

        // Assert
        $this->assertEquals('No reference', $reference);
    }

    /** @test */
    public function it_uses_soft_deletes()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $sacrament->delete();

        // Assert
        $this->assertSoftDeleted('sacraments', ['id' => $sacrament->id]);
        $this->assertNotNull($sacrament->fresh()->deleted_at);
    }

    /** @test */
    public function it_can_restore_soft_deleted_sacrament()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);
        $sacrament->delete();

        // Act
        $sacrament->restore();

        // Assert
        $this->assertDatabaseHas('sacraments', [
            'id' => $sacrament->id,
            'deleted_at' => null,
        ]);
    }
}


