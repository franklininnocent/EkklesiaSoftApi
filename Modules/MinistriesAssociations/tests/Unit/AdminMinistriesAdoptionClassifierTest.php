<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Modules\MinistriesAssociations\Support\AdminMinistriesAdoptionClassifier;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminMinistriesAdoptionClassifierTest extends TestCase
{
    #[Test]
    public function disabled_tenant_is_not_started_or_activated(): void
    {
        $result = AdminMinistriesAdoptionClassifier::classify([
            'module_enabled' => false,
            'organizations_count' => 5,
            'current_events' => 20,
            'prior_events' => 20,
            'feature_categories_used' => 3,
        ]);

        $this->assertSame('disabled', $result['module_status']);
        $this->assertSame('disabled', $result['primary_adoption_status']);
        $this->assertFalse($result['activated']);
        $this->assertFalse($result['active']);
    }

    #[Test]
    public function enabled_without_orgs_is_not_started(): void
    {
        $result = AdminMinistriesAdoptionClassifier::classify([
            'module_enabled' => true,
            'organizations_count' => 0,
            'current_events' => 0,
            'prior_events' => 0,
            'feature_categories_used' => 0,
        ]);

        $this->assertTrue($result['not_started']);
        $this->assertFalse($result['activated']);
        $this->assertSame('not_started', $result['primary_adoption_status']);
    }

    #[Test]
    public function activated_with_activity_is_active(): void
    {
        $result = AdminMinistriesAdoptionClassifier::classify([
            'module_enabled' => true,
            'organizations_count' => 1,
            'current_events' => 3,
            'prior_events' => 3,
            'feature_categories_used' => 1,
        ]);

        $this->assertTrue($result['activated']);
        $this->assertTrue($result['active']);
        $this->assertFalse($result['highly_engaged']);
        $this->assertFalse($result['inactive']);
        $this->assertSame('active', $result['primary_adoption_status']);
    }

    #[Test]
    public function highly_engaged_requires_events_and_categories(): void
    {
        $result = AdminMinistriesAdoptionClassifier::classify([
            'module_enabled' => true,
            'organizations_count' => 2,
            'current_events' => AdminMinistriesDefinitions::HIGHLY_ENGAGED_MIN_EVENTS,
            'prior_events' => 12,
            'feature_categories_used' => AdminMinistriesDefinitions::HIGHLY_ENGAGED_MIN_FEATURE_CATEGORIES,
        ]);

        $this->assertTrue($result['highly_engaged']);
        $this->assertSame('highly_engaged', $result['primary_adoption_status']);
    }

    #[Test]
    public function declining_when_usage_halves(): void
    {
        $result = AdminMinistriesAdoptionClassifier::classify([
            'module_enabled' => true,
            'organizations_count' => 1,
            'current_events' => 2,
            'prior_events' => AdminMinistriesDefinitions::DECLINING_PRIOR_MIN_EVENTS,
            'feature_categories_used' => 1,
        ]);

        $this->assertTrue($result['declining']);
        $this->assertTrue($result['active']);
        $this->assertSame('declining', $result['primary_adoption_status']);
    }

    #[Test]
    public function maps_events_to_feature_categories(): void
    {
        $this->assertSame('organizations', AdminMinistriesAdoptionClassifier::featureCategoryForEvent('organization.created'));
        $this->assertSame('members', AdminMinistriesAdoptionClassifier::featureCategoryForEvent('membership.enrolled'));
        $this->assertNull(AdminMinistriesAdoptionClassifier::featureCategoryForEvent('unknown.event'));
    }
}
