<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileBishopResolutionTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_resolve_current_diocesan_bishop_from_appointments_when_bishop_id_is_null(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);

        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Diocese of Testville',
            'code' => 'TESTVILLE_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bishopId = DB::table('bishops')->insertGetId([
            'full_name' => 'Most Rev. Test Bishop',
            'archdiocese_id' => $archdioceseId,
            'appointed_date' => '2025-05-08',
            'status' => 'active',
            'is_current' => true,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bishop_appointments')->insert([
            'id' => (string) Str::uuid(),
            'bishop_id' => $bishopId,
            'diocese_id' => $archdioceseId,
            'appointed_date' => '2025-05-08',
            'effective_date' => '2025-05-08',
            'canonical_role' => 'diocesan_bishop',
            'appointment_status' => 'current',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ChurchProfile::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'archdiocese_id' => $archdioceseId,
            'bishop_id' => null,
        ]);

        $response = $this->getJson('/api/church-profile')->assertOk();

        $response->assertJsonPath('data.bishop.id', $bishopId);
        $response->assertJsonPath('data.bishop.full_name', 'Most Rev. Test Bishop');
    }

    #[Test]
    public function it_should_resolve_successor_from_appointments_after_succession(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);

        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Diocese of Succession',
            'code' => 'SUCCESSION_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $formerBishopId = DB::table('bishops')->insertGetId([
            'full_name' => 'Most Rev. Former Bishop',
            'archdiocese_id' => $archdioceseId,
            'appointed_date' => '2018-01-01',
            'status' => 'retired',
            'is_current' => false,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentBishopId = DB::table('bishops')->insertGetId([
            'full_name' => 'Most Rev. Current Bishop',
            'archdiocese_id' => $archdioceseId,
            'appointed_date' => '2026-01-01',
            'status' => 'active',
            'is_current' => true,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bishop_appointments')->insert([
            'id' => (string) Str::uuid(),
            'bishop_id' => $formerBishopId,
            'diocese_id' => $archdioceseId,
            'appointed_date' => '2018-01-01',
            'effective_date' => '2018-01-01',
            'ended_date' => '2025-12-31',
            'end_reason' => 'retirement',
            'canonical_role' => 'diocesan_bishop',
            'appointment_status' => 'ended',
            'is_current' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bishop_appointments')->insert([
            'id' => (string) Str::uuid(),
            'bishop_id' => $currentBishopId,
            'diocese_id' => $archdioceseId,
            'appointed_date' => '2026-01-01',
            'effective_date' => '2026-01-01',
            'canonical_role' => 'diocesan_bishop',
            'appointment_status' => 'current',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ChurchProfile::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'archdiocese_id' => $archdioceseId,
            'bishop_id' => null,
        ]);

        $response = $this->getJson('/api/church-profile')->assertOk();

        $response->assertJsonPath('data.bishop.id', $currentBishopId);
        $response->assertJsonPath('data.bishop.full_name', 'Most Rev. Current Bishop');
    }
}
