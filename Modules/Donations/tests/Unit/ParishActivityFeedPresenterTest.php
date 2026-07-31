<?php

namespace Modules\Donations\Tests\Unit;

use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Services\ParishActivityFeedPresenter;
use PHPUnit\Framework\TestCase;

class ParishActivityFeedPresenterTest extends TestCase
{
    private ParishActivityFeedPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new ParishActivityFeedPresenter();
    }

    public function test_it_presents_donor_created_in_human_language(): void
    {
        $log = new DonationAuditLog([
            'id' => 1,
            'actor_user_id' => 5,
            'event' => 'donor.created',
            'target_type' => 'donor',
            'target_id' => '019ec173-0997-7083-8db7-a417703a5ad6',
            'new_values' => ['name' => 'John Mathew'],
            'created_at' => now(),
        ]);

        $item = $this->presenter->present($log, [5 => 'Parish Administrator']);

        $this->assertSame('New Donor Added', $item['title']);
        $this->assertSame('John Mathew was added as a donor.', $item['description']);
        $this->assertSame('John Mathew', $item['subject_name']);
        $this->assertSame('Parish Administrator', $item['actor_name']);
        $this->assertSame('donors', $item['category']);
        $this->assertSame('/donations/donors', $item['action_path']);
        $this->assertArrayNotHasKey('event', $item);
        $this->assertArrayNotHasKey('target_id', $item);
    }

    public function test_it_presents_category_defaults_seeded_without_uuid(): void
    {
        $log = new DonationAuditLog([
            'id' => 2,
            'event' => 'category.defaults_seeded',
            'target_type' => 'donation_category',
            'target_id' => '47',
            'new_values' => ['count' => 8],
            'created_at' => now(),
        ]);

        $item = $this->presenter->present($log, []);

        $this->assertSame('Default Categories Added', $item['title']);
        $this->assertStringContainsString('8 categories', $item['description']);
        $this->assertSame('Parish team member', $item['actor_name']);
    }

    public function test_it_presents_plan_created_with_name(): void
    {
        $log = new DonationAuditLog([
            'id' => 3,
            'event' => 'plan.created',
            'target_type' => 'plan',
            'target_id' => '019ec160-aaa7-7013-b3cf-80e9737901cb',
            'new_values' => ['name' => 'Festival Collection 2026', 'default_amount' => 500],
            'created_at' => now(),
        ]);

        $item = $this->presenter->present($log, []);

        $this->assertSame('New Contribution Plan Created', $item['title']);
        $this->assertSame('Festival Collection 2026 was created.', $item['description']);
        $this->assertSame(500.0, $item['amount']);
    }
}
