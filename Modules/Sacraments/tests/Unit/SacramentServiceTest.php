<?php

namespace Modules\Sacraments\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use App\Models\User;

class SacramentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SacramentService $service;
    protected $repositoryMock;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repository
        $this->repositoryMock = Mockery::mock(SacramentRepository::class);
        
        // Create service with mocked repository
        $this->service = new SacramentService($this->repositoryMock);

        // Create and authenticate user
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_get_all_sacraments_with_pagination()
    {
        // Arrange
        $params = ['page' => 1, 'per_page' => 20];
        $expectedResult = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);

        $this->repositoryMock
            ->shouldReceive('getPaginated')
            ->once()
            ->with($params)
            ->andReturn($expectedResult);

        // Act
        $result = $this->service->getAll($params);

        // Assert
        $this->assertEquals($expectedResult, $result);
    }

    /** @test */
    public function it_can_get_sacrament_by_id()
    {
        // Arrange
        $sacrament = Sacrament::factory()->make(['id' => 1]);

        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn($sacrament);

        // Act
        $result = $this->service->getById(1);

        // Assert
        $this->assertEquals($sacrament, $result);
    }

    /** @test */
    public function it_returns_null_when_sacrament_not_found()
    {
        // Arrange
        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(999)
            ->andReturn(null);

        // Act
        $result = $this->service->getById(999);

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    public function it_can_create_sacrament_with_created_by()
    {
        // Arrange
        $data = [
            'tenant_id' => 1,
            'sacrament_type_id' => 1,
            'recipient_name' => 'John Doe',
            'date_administered' => '2025-01-15',
        ];

        $expectedSacrament = Sacrament::factory()->make(array_merge($data, [
            'created_by' => $this->user->id,
        ]));

        $this->repositoryMock
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['created_by'] === $this->user->id;
            }))
            ->andReturn($expectedSacrament);

        // Act
        $result = $this->service->create($data);

        // Assert
        $this->assertEquals($expectedSacrament, $result);
    }

    /** @test */
    public function it_can_update_sacrament_with_updated_by()
    {
        // Arrange
        $sacrament = Sacrament::factory()->make(['id' => 1]);
        $updateData = [
            'recipient_name' => 'Updated Name',
        ];

        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn($sacrament);

        $this->repositoryMock
            ->shouldReceive('update')
            ->once()
            ->with($sacrament, Mockery::on(function ($arg) {
                return $arg['updated_by'] === $this->user->id 
                    && $arg['recipient_name'] === 'Updated Name';
            }))
            ->andReturn($sacrament);

        // Act
        $result = $this->service->update(1, $updateData);

        // Assert
        $this->assertEquals($sacrament, $result);
    }

    /** @test */
    public function it_returns_null_when_updating_non_existent_sacrament()
    {
        // Arrange
        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(999)
            ->andReturn(null);

        // Act
        $result = $this->service->update(999, ['recipient_name' => 'Test']);

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    public function it_can_delete_sacrament()
    {
        // Arrange
        $sacrament = Sacrament::factory()->make(['id' => 1]);

        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn($sacrament);

        $this->repositoryMock
            ->shouldReceive('delete')
            ->once()
            ->with($sacrament)
            ->andReturn(true);

        // Act
        $result = $this->service->delete(1);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_when_deleting_non_existent_sacrament()
    {
        // Arrange
        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(999)
            ->andReturn(null);

        // Act
        $result = $this->service->delete(999);

        // Assert
        $this->assertFalse($result);
    }
}


