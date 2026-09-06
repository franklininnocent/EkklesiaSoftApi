<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoadTestFixtureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seed_command_creates_tagged_tenants_families_and_members(): void
    {
        $tag = 'phpunit-'.uniqid();

        $this->artisan('tenants:seed-load-test-data', [
            '--tenants' => 2,
            '--members' => 12,
            '--members-per-family' => 3,
            '--tag' => $tag,
            '--force' => true,
        ])->assertSuccessful();

        $tenants = Tenant::query()->where('settings->load_test_tag', $tag)->get();
        $this->assertCount(2, $tenants);

        $this->assertSame(12, FamilyMember::query()->whereIn('tenant_id', $tenants->pluck('id'))->count());
        $this->assertSame(4, Family::query()->whereIn('tenant_id', $tenants->pluck('id'))->count());
    }

    #[Test]
    public function benchmark_command_returns_ok_for_seeded_fixture(): void
    {
        $tag = 'bench-'.uniqid();

        $this->artisan('tenants:seed-load-test-data', [
            '--tenants' => 1,
            '--members' => 6,
            '--members-per-family' => 3,
            '--tag' => $tag,
            '--force' => true,
        ])->assertSuccessful();

        $this->artisan('tenants:load-test-benchmark', [
            '--tag' => $tag,
            '--warmup' => 1,
            '--iterations' => 2,
            '--count-queries' => true,
        ])->assertSuccessful();
    }

    #[Test]
    public function cleanup_command_removes_tagged_tenants(): void
    {
        $tag = 'purge-'.uniqid();

        $this->artisan('tenants:seed-load-test-data', [
            '--tenants' => 1,
            '--members' => 3,
            '--tag' => $tag,
            '--force' => true,
        ])->assertSuccessful();

        $tenantIds = Tenant::query()->where('settings->load_test_tag', $tag)->pluck('id');

        $this->artisan('tenants:cleanup-load-test-data', [
            '--tag' => $tag,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Tenant::query()->where('settings->load_test_tag', $tag)->count());
        $this->assertSame(0, FamilyMember::query()->whereIn('tenant_id', $tenantIds)->count());
    }
}
