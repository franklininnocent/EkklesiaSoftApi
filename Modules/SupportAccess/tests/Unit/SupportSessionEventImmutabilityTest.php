<?php

namespace Modules\SupportAccess\Tests\Unit;

use Modules\SupportAccess\Models\SupportSessionEvent;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportSessionEventImmutabilityTest extends TestCase
{
    #[Test]
    public function updating_an_event_is_rejected(): void
    {
        $event = new SupportSessionEvent([
            'support_session_id' => '11111111-1111-1111-1111-111111111111',
            'actor_user_id' => 1,
            'effective_tenant_id' => 1,
            'event_type' => 'page_view',
            'created_at' => now(),
        ]);
        $event->exists = true;
        $event->syncOriginal();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->event_type = 'tampered';
        $event->save();
    }

    #[Test]
    public function deleting_an_event_is_rejected(): void
    {
        $event = new SupportSessionEvent([
            'support_session_id' => '11111111-1111-1111-1111-111111111111',
            'actor_user_id' => 1,
            'effective_tenant_id' => 1,
            'event_type' => 'page_view',
            'created_at' => now(),
        ]);
        $event->exists = true;
        $event->syncOriginal();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->delete();
    }
}
