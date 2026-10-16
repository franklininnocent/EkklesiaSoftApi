<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BCC\Models\BCC;
use Modules\Family\app\Services\BccRelocationService;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BccRelocationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preview_does_not_return_another_tenants_bcc_name(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();
        $target = BCC::factory()->active()->create(['tenant_id' => $tenantA->id, 'name' => 'Target BCC']);
        $foreign = BCC::factory()->active()->create(['tenant_id' => $tenantB->id, 'name' => 'Secret Foreign BCC']);
        $family = Family::factory()->create([
            'tenant_id' => $tenantA->id,
            'status' => 'active',
            'bcc_id' => $foreign->id,
        ]);

        $preview = app(BccRelocationService::class)->previewImpact(
            $family->id,
            $target->id,
            $tenantA->id,
        );

        $this->assertNotSame('Secret Foreign BCC', $preview['current_bcc_name']);
        $this->assertSame($target->id, $preview['target_bcc_id']);
    }
}
