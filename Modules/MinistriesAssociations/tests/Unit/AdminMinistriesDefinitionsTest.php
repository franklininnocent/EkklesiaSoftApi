<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminMinistriesDefinitionsTest extends TestCase
{
    #[Test]
    public function to_array_exposes_locked_adoption_contract(): void
    {
        $payload = AdminMinistriesDefinitions::toArray();

        $this->assertSame('ministries_associations', $payload['feature_key']);
        $this->assertSame(30, $payload['default_activity_window_days']);
        $this->assertContains('organization.created', $payload['meaningful_events']);
        $this->assertContains('guest_member.linked_to_parishioner', $payload['meaningful_events']);
        $this->assertSame(
            ['organizations', 'members', 'leadership', 'taxonomies', 'guests'],
            $payload['feature_categories']
        );
        $this->assertArrayHasKey('not_started', $payload['adoption_status']);
        $this->assertArrayHasKey('highly_engaged', $payload['adoption_status']);
    }
}
