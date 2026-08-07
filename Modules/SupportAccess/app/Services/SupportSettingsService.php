<?php

namespace Modules\SupportAccess\Services;

use Modules\SupportAccess\Models\SupportSessionSetting;

use Modules\SupportAccess\Services\SupportTicketValidationService;

class SupportSettingsService
{
    public const KEY_OPS = 'ops';

    /** @var list<int> */
    public const ALLOWED_TIMEOUTS = [15, 30, 60, 120];

    /** @var list<string> */
    public const NOTIFICATION_MODES = ['never', 'immediate', 'digest'];

    /** @var list<string> */
    public const TICKET_VALIDATION_MODES = SupportTicketValidationService::MODES;

    /** @var list<string> */
    public const IP_BINDING_MODES = ['off', 'soft', 'strict'];

    /**
     * @return array{
     *   timeout_minutes:int,
     *   max_concurrent_sessions:int,
     *   max_sessions_per_user:int,
     *   start_rate_limit_per_hour:int,
     *   notification_mode:string,
     *   customer_disclosure_enabled:bool,
     *   emergency_requires_approval:bool,
     *   require_customer_grant:bool,
     *   jit_enabled:bool,
     *   jit_timeout_minutes:int,
     *   approval_request_ttl_minutes:int,
     *   allowed_timeouts:list<int>,
     *   notification_modes:list<string>
     * }
     */
    public function getOpsSettings(): array
    {
        $defaults = $this->defaults();
        $row = SupportSessionSetting::query()->where('key', self::KEY_OPS)->first();
        if (! $row || ! is_array($row->value)) {
            return $defaults;
        }

        $value = $row->value;

        $timeout = (int) ($value['timeout_minutes'] ?? $defaults['timeout_minutes']);
        if (! in_array($timeout, self::ALLOWED_TIMEOUTS, true)) {
            $timeout = $defaults['timeout_minutes'];
        }

        $notification = (string) ($value['notification_mode'] ?? $defaults['notification_mode']);
        if (! in_array($notification, self::NOTIFICATION_MODES, true)) {
            $notification = $defaults['notification_mode'];
        }

        $jitTimeout = (int) ($value['jit_timeout_minutes'] ?? $defaults['jit_timeout_minutes']);
        $jitTimeout = max(5, min(120, $jitTimeout));

        $approvalTtl = (int) ($value['approval_request_ttl_minutes'] ?? $defaults['approval_request_ttl_minutes']);
        $approvalTtl = max(5, min(240, $approvalTtl));

        $ticketMode = (string) ($value['ticket_validation_mode'] ?? $defaults['ticket_validation_mode']);
        if (! in_array($ticketMode, self::TICKET_VALIDATION_MODES, true)) {
            $ticketMode = $defaults['ticket_validation_mode'];
        }

        $ipBinding = (string) ($value['ip_binding_mode'] ?? $defaults['ip_binding_mode']);
        if (! in_array($ipBinding, self::IP_BINDING_MODES, true)) {
            $ipBinding = $defaults['ip_binding_mode'];
        }

        return [
            'timeout_minutes' => $timeout,
            'max_concurrent_sessions' => max(1, (int) ($value['max_concurrent_sessions'] ?? $defaults['max_concurrent_sessions'])),
            'max_sessions_per_user' => max(1, (int) ($value['max_sessions_per_user'] ?? $defaults['max_sessions_per_user'])),
            'start_rate_limit_per_hour' => max(1, (int) ($value['start_rate_limit_per_hour'] ?? $defaults['start_rate_limit_per_hour'])),
            'notification_mode' => $notification,
            'customer_disclosure_enabled' => (bool) ($value['customer_disclosure_enabled'] ?? $defaults['customer_disclosure_enabled']),
            'emergency_requires_approval' => (bool) ($value['emergency_requires_approval'] ?? $defaults['emergency_requires_approval']),
            'require_customer_grant' => (bool) ($value['require_customer_grant'] ?? $defaults['require_customer_grant']),
            'jit_enabled' => (bool) ($value['jit_enabled'] ?? $defaults['jit_enabled']),
            'jit_timeout_minutes' => $jitTimeout,
            'approval_request_ttl_minutes' => $approvalTtl,
            'require_ticket_ref' => (bool) ($value['require_ticket_ref'] ?? $defaults['require_ticket_ref']),
            'ticket_validation_mode' => $ticketMode,
            'ip_binding_mode' => $ipBinding,
            'allowed_timeouts' => self::ALLOWED_TIMEOUTS,
            'notification_modes' => self::NOTIFICATION_MODES,
            'ticket_validation_modes' => self::TICKET_VALIDATION_MODES,
            'ip_binding_modes' => self::IP_BINDING_MODES,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateOpsSettings(array $input): array
    {
        $current = $this->getOpsSettings();

        if (array_key_exists('timeout_minutes', $input)) {
            $timeout = (int) $input['timeout_minutes'];
            if (! in_array($timeout, self::ALLOWED_TIMEOUTS, true)) {
                throw new \InvalidArgumentException('Timeout must be one of 15, 30, 60, or 120 minutes.');
            }
            $current['timeout_minutes'] = $timeout;
        }

        if (array_key_exists('max_concurrent_sessions', $input)) {
            $current['max_concurrent_sessions'] = max(1, min(500, (int) $input['max_concurrent_sessions']));
        }

        if (array_key_exists('max_sessions_per_user', $input)) {
            $current['max_sessions_per_user'] = max(1, min(20, (int) $input['max_sessions_per_user']));
        }

        if (array_key_exists('start_rate_limit_per_hour', $input)) {
            $current['start_rate_limit_per_hour'] = max(1, min(1000, (int) $input['start_rate_limit_per_hour']));
        }

        if (array_key_exists('notification_mode', $input)) {
            $mode = (string) $input['notification_mode'];
            if (! in_array($mode, self::NOTIFICATION_MODES, true)) {
                throw new \InvalidArgumentException('Notification mode must be never, immediate, or digest.');
            }
            $current['notification_mode'] = $mode;
        }

        if (array_key_exists('customer_disclosure_enabled', $input)) {
            $current['customer_disclosure_enabled'] = (bool) $input['customer_disclosure_enabled'];
        }

        if (array_key_exists('emergency_requires_approval', $input)) {
            $current['emergency_requires_approval'] = (bool) $input['emergency_requires_approval'];
        }

        if (array_key_exists('require_customer_grant', $input)) {
            $current['require_customer_grant'] = (bool) $input['require_customer_grant'];
        }

        if (array_key_exists('jit_enabled', $input)) {
            $current['jit_enabled'] = (bool) $input['jit_enabled'];
        }

        if (array_key_exists('jit_timeout_minutes', $input)) {
            $current['jit_timeout_minutes'] = max(5, min(120, (int) $input['jit_timeout_minutes']));
        }

        if (array_key_exists('approval_request_ttl_minutes', $input)) {
            $current['approval_request_ttl_minutes'] = max(5, min(240, (int) $input['approval_request_ttl_minutes']));
        }

        if (array_key_exists('require_ticket_ref', $input)) {
            $current['require_ticket_ref'] = (bool) $input['require_ticket_ref'];
        }

        if (array_key_exists('ticket_validation_mode', $input)) {
            $mode = (string) $input['ticket_validation_mode'];
            if (! in_array($mode, self::TICKET_VALIDATION_MODES, true)) {
                throw new \InvalidArgumentException('Ticket validation mode must be off, required_format, or adapter.');
            }
            $current['ticket_validation_mode'] = $mode;
        }

        if (array_key_exists('ip_binding_mode', $input)) {
            $mode = (string) $input['ip_binding_mode'];
            if (! in_array($mode, self::IP_BINDING_MODES, true)) {
                throw new \InvalidArgumentException('IP binding mode must be off, soft, or strict.');
            }
            $current['ip_binding_mode'] = $mode;
        }

        unset(
            $current['allowed_timeouts'],
            $current['notification_modes'],
            $current['ticket_validation_modes'],
            $current['ip_binding_modes'],
        );

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => self::KEY_OPS],
            ['value' => $current]
        );

        return $this->getOpsSettings();
    }

    /**
     * @return array{
     *   timeout_minutes:int,
     *   max_concurrent_sessions:int,
     *   max_sessions_per_user:int,
     *   start_rate_limit_per_hour:int,
     *   notification_mode:string,
     *   customer_disclosure_enabled:bool,
     *   emergency_requires_approval:bool,
     *   require_customer_grant:bool,
     *   jit_enabled:bool,
     *   jit_timeout_minutes:int,
     *   approval_request_ttl_minutes:int,
     *   require_ticket_ref:bool,
     *   ticket_validation_mode:string,
     *   allowed_timeouts:list<int>,
     *   notification_modes:list<string>,
     *   ticket_validation_modes:list<string>
     * }
     */
    public function defaults(): array
    {
        return [
            'timeout_minutes' => 30,
            'max_concurrent_sessions' => 25,
            'max_sessions_per_user' => 1,
            'start_rate_limit_per_hour' => 30,
            'notification_mode' => 'never',
            'customer_disclosure_enabled' => false,
            'emergency_requires_approval' => true,
            'require_customer_grant' => false,
            'jit_enabled' => true,
            'jit_timeout_minutes' => 15,
            'approval_request_ttl_minutes' => 60,
            'require_ticket_ref' => false,
            'ticket_validation_mode' => SupportTicketValidationService::MODE_OFF,
            'ip_binding_mode' => 'soft',
            'allowed_timeouts' => self::ALLOWED_TIMEOUTS,
            'notification_modes' => self::NOTIFICATION_MODES,
            'ticket_validation_modes' => self::TICKET_VALIDATION_MODES,
            'ip_binding_modes' => self::IP_BINDING_MODES,
        ];
    }
}
