<?php

namespace Modules\Sacraments\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Models\Tenant;

class SacramentTypeModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        // Arrange
        $fillable = [
            'name', 'code', 'category', 'description', 'theological_significance',
            'display_order', 'min_age_years', 'typical_age_years', 'repeatable',
            'requires_minister', 'minister_type', 'active',
        ];

        // Act
        $model = new SacramentType();

        // Assert
        $this->assertEquals($fillable, $model->getFillable());
    }

    /** @test */
    public function it_has_correct_casts()
    {
        // Arrange & Act
        $model = new SacramentType();
        $casts = $model->getCasts();

        // Assert
        $this->assertEquals('integer', $casts['display_order']);
        $this->assertEquals('integer', $casts['min_age_years']);
        $this->assertEquals('integer', $casts['typical_age_years']);
        $this->assertEquals('boolean', $casts['repeatable']);
        $this->assertEquals('boolean', $casts['requires_minister']);
        $this->assertEquals('boolean', $casts['active']);
    }

    /** @test */
    public function it_has_many_sacraments()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $type = SacramentType::factory()->create();
        
        Sacrament::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $type->id,
        ]);

        // Act
        $sacraments = $type->sacraments;

        // Assert
        $this->assertEquals(3, $sacraments->count());
        $this->assertInstanceOf(Sacrament::class, $sacraments->first());
    }

    /** @test */
    public function it_can_scope_active_types()
    {
        // Arrange
        SacramentType::factory()->count(3)->create(['active' => true]);
        SacramentType::factory()->count(2)->create(['active' => false]);

        // Act
        $activeTypes = SacramentType::active()->get();

        // Assert
        $this->assertEquals(3, $activeTypes->count());
        $activeTypes->each(function ($type) {
            $this->assertTrue($type->active);
        });
    }

    /** @test */
    public function it_can_scope_ordered()
    {
        // Arrange
        SacramentType::factory()->create(['display_order' => 3, 'name' => 'Third']);
        SacramentType::factory()->create(['display_order' => 1, 'name' => 'First']);
        SacramentType::factory()->create(['display_order' => 2, 'name' => 'Second']);

        // Act
        $orderedTypes = SacramentType::ordered()->get();

        // Assert
        $this->assertEquals('First', $orderedTypes->first()->name);
        $this->assertEquals('Third', $orderedTypes->last()->name);
    }

    /** @test */
    public function it_can_filter_by_category()
    {
        // Arrange
        SacramentType::factory()->count(2)->create(['category' => 'initiation']);
        SacramentType::factory()->count(3)->create(['category' => 'healing']);

        // Act
        $healingTypes = SacramentType::byCategory('healing')->get();

        // Assert
        $this->assertEquals(3, $healingTypes->count());
        $healingTypes->each(function ($type) {
            $this->assertEquals('healing', $type->category);
        });
    }

    /** @test */
    public function it_can_check_if_repeatable()
    {
        // Arrange
        $repeatableType = SacramentType::factory()->create(['repeatable' => true]);
        $nonRepeatableType = SacramentType::factory()->create(['repeatable' => false]);

        // Act & Assert
        $this->assertTrue($repeatableType->isRepeatable());
        $this->assertFalse($nonRepeatableType->isRepeatable());
    }

    /** @test */
    public function it_generates_age_range_attribute_with_both_ages()
    {
        // Arrange
        $type = SacramentType::factory()->create([
            'min_age_years' => 7,
            'typical_age_years' => 14,
        ]);

        // Act
        $ageRange = $type->age_range;

        // Assert
        $this->assertEquals('7-14 years', $ageRange);
    }

    /** @test */
    public function it_generates_age_range_attribute_with_min_age_only()
    {
        // Arrange
        $type = SacramentType::factory()->create([
            'min_age_years' => 18,
            'typical_age_years' => null,
        ]);

        // Act
        $ageRange = $type->age_range;

        // Assert
        $this->assertEquals('18+ years', $ageRange);
    }

    /** @test */
    public function it_generates_age_range_attribute_with_typical_age_only()
    {
        // Arrange
        $type = SacramentType::factory()->create([
            'min_age_years' => null,
            'typical_age_years' => 8,
        ]);

        // Act
        $ageRange = $type->age_range;

        // Assert
        $this->assertEquals('Around 8 years', $ageRange);
    }

    /** @test */
    public function it_generates_age_range_attribute_with_no_restrictions()
    {
        // Arrange
        $type = SacramentType::factory()->create([
            'min_age_years' => null,
            'typical_age_years' => null,
        ]);

        // Act
        $ageRange = $type->age_range;

        // Assert
        $this->assertEquals('No age restriction', $ageRange);
    }

    /** @test */
    public function it_has_unique_code_constraint()
    {
        // Arrange
        SacramentType::factory()->create(['code' => 'BAPTISM']);

        // Act & Assert
        $this->expectException(\Illuminate\Database\QueryException::class);
        SacramentType::factory()->create(['code' => 'BAPTISM']);
    }
}


