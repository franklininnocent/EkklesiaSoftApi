<?php

namespace Modules\ApplicationAccess\Support;

final class ApplicationAccessFilterCatalog
{
    /** @var list<string> */
    public const SESSION_STATUSES = [
        ApplicationAccessEnums::SESSION_ACTIVE,
        ApplicationAccessEnums::SESSION_IDLE,
        ApplicationAccessEnums::SESSION_ENDED,
        ApplicationAccessEnums::SESSION_EXPIRED,
        ApplicationAccessEnums::SESSION_REVOKED,
    ];

    /** @var list<string> */
    public const IDENTITY_TYPES = [
        ApplicationAccessEnums::IDENTITY_SUPER_ADMIN,
        ApplicationAccessEnums::IDENTITY_EKKLESIA_USER,
        ApplicationAccessEnums::IDENTITY_SUPPORT_OPERATOR,
        ApplicationAccessEnums::IDENTITY_TENANT_USER,
        ApplicationAccessEnums::IDENTITY_NO_TENANT,
        ApplicationAccessEnums::IDENTITY_ANONYMOUS,
        ApplicationAccessEnums::IDENTITY_UNKNOWN,
    ];

    /** @var list<string> */
    public const ACCESS_CONTEXTS = [
        ApplicationAccessEnums::CONTEXT_PUBLIC,
        ApplicationAccessEnums::CONTEXT_AUTHENTICATION,
        ApplicationAccessEnums::CONTEXT_TENANT,
        ApplicationAccessEnums::CONTEXT_EKKLESIA,
        ApplicationAccessEnums::CONTEXT_SUPPORT,
        ApplicationAccessEnums::CONTEXT_API,
        ApplicationAccessEnums::CONTEXT_SYSTEM,
    ];

    /** @var list<string> */
    public const EVENT_TYPES = [
        'VIEW', 'LIST', 'SEARCH', 'CREATE', 'UPDATE', 'DELETE',
        'DOWNLOAD', 'EXPORT', 'PRINT', 'LOGIN', 'LOGOUT', 'REVOKE',
        'BLOCK', 'UNBLOCK', 'APPROVE', 'REJECT',
        'SESSION_CREATED', 'SESSION_RENEWED', 'AUTH_FAILURE', 'ACCESS_DENIED',
    ];

    /** @var list<string> */
    public const ACTIONS = [
        'VIEW', 'LIST', 'SEARCH', 'CREATE', 'UPDATE', 'DELETE',
        'DOWNLOAD', 'EXPORT', 'PRINT', 'LOGIN', 'LOGOUT', 'REVOKE',
        'BLOCK', 'UNBLOCK', 'APPROVE', 'REJECT', 'RENEW',
    ];

    /** @var list<string> */
    public const AUTHORIZATION_RESULTS = ['allowed', 'denied'];

    /** @var list<string> */
    public const SECURITY_EVENT_TYPES = [
        'LOGIN_SUCCESS', 'LOGIN_FAILURE', 'AUTH_FAILURE', 'ACCESS_DENIED',
        'PRIVILEGED_OPERATION', 'CROSS_TENANT_ATTEMPT', 'SUPPORT_SESSION_VIOLATION',
    ];

    /** @var list<string> */
    public const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** @var list<string> */
    public const SIGNAL_TYPES = [
        'INVALID_TOKEN', 'REPEATED_LOGIN_FAILURES', 'MULTIPLE_ACCOUNTS_FROM_IP',
        'REPEATED_403', 'CROSS_TENANT_ATTEMPT', 'PERMISSION_PROBING',
        'SUPPORT_SESSION_VIOLATION', 'HIGH_VOLUME_UNAUTHENTICATED_ACTIVITY',
    ];

    public static function isAllowed(string $field, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return match ($field) {
            'status' => in_array($value, self::SESSION_STATUSES, true),
            'identity_type' => in_array($value, self::IDENTITY_TYPES, true),
            'access_context' => in_array($value, self::ACCESS_CONTEXTS, true),
            'event_type' => in_array($value, self::EVENT_TYPES, true),
            'action' => in_array($value, self::ACTIONS, true),
            'authorization_result' => in_array($value, self::AUTHORIZATION_RESULTS, true),
            'severity' => in_array($value, self::SEVERITIES, true),
            'signal_type' => in_array($value, self::SIGNAL_TYPES, true),
            default => true,
        };
    }
}
