<?php

namespace Modules\Sacraments\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Services\Certificates\SacramentCertificateService;
use Modules\Sacraments\Services\SacramentAuditService;
use Modules\Sacraments\Services\SacramentDuplicateDetector;
use Modules\Sacraments\Services\SacramentIdempotencyService;
use Modules\Sacraments\Services\SacramentLegacyDenormMapper;
use Modules\Sacraments\Services\SacramentParticipantValidator;
use Modules\Sacraments\Services\SacramentRecipientResolver;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Services\SacramentTypedAttributesValidator;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SacramentService $service;

    protected $repositoryMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryMock = Mockery::mock(SacramentRepository::class);
        $privacy = new SacramentPrivacyAccess(app(SacramentDefinitionRegistry::class));

        $this->service = new SacramentService(
            $this->repositoryMock,
            Mockery::mock(SacramentParticipantValidator::class),
            Mockery::mock(SacramentLegacyDenormMapper::class),
            Mockery::mock(SacramentIdempotencyService::class),
            Mockery::mock(SacramentAuditService::class),
            Mockery::mock(SacramentCertificateService::class),
            Mockery::mock(SacramentDuplicateDetector::class),
            Mockery::mock(SacramentTypedAttributesValidator::class),
            $privacy,
            Mockery::mock(SacramentRecipientResolver::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_can_get_all_sacraments_with_pagination()
    {
        $params = ['page' => 1, 'per_page' => 20];
        $expectedResult = new LengthAwarePaginator([], 0, 20);

        $this->repositoryMock
            ->shouldReceive('getPaginated')
            ->once()
            ->andReturn($expectedResult);

        $result = $this->service->getAll($params);

        $this->assertEquals($expectedResult, $result);
    }

    #[Test]
    public function it_can_get_sacrament_by_id()
    {
        $sacrament = Sacrament::factory()->make(['id' => 1]);

        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn($sacrament);

        $this->assertEquals($sacrament, $this->service->getById(1));
    }

    #[Test]
    public function it_returns_null_when_sacrament_not_found()
    {
        $this->repositoryMock
            ->shouldReceive('findById')
            ->once()
            ->with(999)
            ->andReturn(null);

        $this->assertNull($this->service->getById(999));
    }
}
