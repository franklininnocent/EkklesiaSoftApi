<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SupportAccess\Models\SupportSessionSetting;
use Modules\SupportAccess\Services\SupportSettingsService;
use Modules\SupportAccess\Services\SupportTicketValidationService;
use Modules\SupportAccess\Support\NullExternalHelpdeskTicketAdapter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportTicketValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function require_ticket_ref_rejects_empty(): void
    {
        $this->writeOps([
            'require_ticket_ref' => true,
            'ticket_validation_mode' => 'off',
        ]);

        $service = app(SupportTicketValidationService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ticket reference is required');
        $service->assertValid(null);
    }

    #[Test]
    public function required_format_rejects_invalid_ticket(): void
    {
        $this->writeOps([
            'require_ticket_ref' => false,
            'ticket_validation_mode' => 'required_format',
        ]);

        $service = app(SupportTicketValidationService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('format is invalid');
        $service->assertValid('??');
    }

    #[Test]
    public function required_format_accepts_valid_ticket(): void
    {
        $this->writeOps([
            'require_ticket_ref' => true,
            'ticket_validation_mode' => 'required_format',
        ]);

        app(SupportTicketValidationService::class)->assertValid('HD-12345');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function adapter_mode_fails_closed_with_null_adapter(): void
    {
        $this->writeOps([
            'require_ticket_ref' => false,
            'ticket_validation_mode' => 'adapter',
        ]);

        $service = new SupportTicketValidationService(
            app(SupportSettingsService::class),
            new NullExternalHelpdeskTicketAdapter(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        $service->assertValid('HD-1');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeOps(array $overrides): void
    {
        $defaults = app(SupportSettingsService::class)->defaults();
        unset($defaults['allowed_timeouts'], $defaults['notification_modes'], $defaults['ticket_validation_modes']);

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => SupportSettingsService::KEY_OPS],
            ['value' => array_merge($defaults, $overrides)]
        );
    }
}
