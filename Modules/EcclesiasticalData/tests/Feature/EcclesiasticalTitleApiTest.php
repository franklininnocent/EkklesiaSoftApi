<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\EcclesiasticalData\Tests\Support\CreatesEkklesiaTestUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-list-edit
 */
class EcclesiasticalTitleApiTest extends TestCase
{
    use CreatesEkklesiaTestUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('ecclesiastical_titles')->insert([
            'title' => 'Archbishop',
            'abbreviation' => 'Abp.',
            'description' => 'Test',
            'hierarchy_level' => 3,
            'display_order' => 3,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_returns_active_ecclesiastical_titles_for_dropdowns(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->getJson('/api/ecclesiastical/titles');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $titles = $response->json('data');
        $this->assertIsArray($titles);
        $this->assertNotEmpty($titles);
        $this->assertArrayHasKey('id', $titles[0]);
        $this->assertArrayHasKey('title', $titles[0]);
        $this->assertSame('Archbishop', $titles[0]['title']);
    }
}
