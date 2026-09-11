<?php

namespace Modules\ApplicationAccess\Support;

final class ApplicationAccessEnums
{
    public const IDENTITY_SUPER_ADMIN = 'SUPER_ADMIN';

    public const IDENTITY_EKKLESIA_USER = 'EKKLESIA_USER';

    public const IDENTITY_SUPPORT_OPERATOR = 'SUPPORT_OPERATOR';

    public const IDENTITY_TENANT_USER = 'AUTHENTICATED_TENANT_USER';

    public const IDENTITY_NO_TENANT = 'AUTHENTICATED_USER_WITHOUT_TENANT';

    public const IDENTITY_ANONYMOUS = 'ANONYMOUS_VISITOR';

    public const IDENTITY_UNKNOWN = 'UNKNOWN_USER';

    public const CONTEXT_PUBLIC = 'PUBLIC';

    public const CONTEXT_AUTHENTICATION = 'AUTHENTICATION';

    public const CONTEXT_TENANT = 'TENANT';

    public const CONTEXT_EKKLESIA = 'EKKLESIA';

    public const CONTEXT_SUPPORT = 'SUPPORT';

    public const CONTEXT_API = 'API';

    public const CONTEXT_SYSTEM = 'SYSTEM';

    public const SESSION_ACTIVE = 'ACTIVE';

    public const SESSION_IDLE = 'IDLE';

    public const SESSION_ENDED = 'ENDED';

    public const SESSION_EXPIRED = 'EXPIRED';

    public const SESSION_REVOKED = 'REVOKED';

    public const RISK_LOW = 'LOW';

    public const RISK_MEDIUM = 'MEDIUM';

    public const RISK_HIGH = 'HIGH';

    public const RISK_CRITICAL = 'CRITICAL';

    public const TELEMETRY_APPLICATION = 'APPLICATION_TELEMETRY';

    public const TELEMETRY_EDGE = 'EDGE_TELEMETRY';
}
