<?php

namespace Modules\SupportAccess\Services;

use Modules\SupportAccess\Contracts\ExternalHelpdeskTicketAdapter;
use RuntimeException;

/**
 * Applies platform ticket_ref policy on support session / approval starts.
 */
class SupportTicketValidationService
{
    public const MODE_OFF = 'off';

    public const MODE_REQUIRED_FORMAT = 'required_format';

    public const MODE_ADAPTER = 'adapter';

    /** @var list<string> */
    public const MODES = [self::MODE_OFF, self::MODE_REQUIRED_FORMAT, self::MODE_ADAPTER];

    public function __construct(
        private readonly SupportSettingsService $settings,
        private readonly ExternalHelpdeskTicketAdapter $adapter,
    ) {
    }

    public function assertValid(?string $ticketRef): void
    {
        $ops = $this->settings->getOpsSettings();
        $ref = is_string($ticketRef) ? trim($ticketRef) : '';

        if (($ops['require_ticket_ref'] ?? false) && $ref === '') {
            throw new RuntimeException('A helpdesk ticket reference is required to start this support session.');
        }

        if ($ref === '') {
            return;
        }

        $mode = (string) ($ops['ticket_validation_mode'] ?? self::MODE_OFF);

        if ($mode === self::MODE_OFF) {
            return;
        }

        if ($mode === self::MODE_REQUIRED_FORMAT) {
            $pattern = (string) config(
                'supportaccess.ticket_ref_pattern',
                '/^#?[A-Za-z0-9][-A-Za-z0-9_\/]{2,64}$/'
            );
            if (@preg_match($pattern, $ref) !== 1) {
                throw new RuntimeException('Ticket reference format is invalid.');
            }

            return;
        }

        if ($mode === self::MODE_ADAPTER) {
            $this->adapter->assertTicketExists($ref);

            return;
        }

        throw new RuntimeException('Unsupported ticket_validation_mode.');
    }
}
