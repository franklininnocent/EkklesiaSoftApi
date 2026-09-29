<?php

namespace Modules\ApplicationAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApplicationSecurityEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'application_security_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'event_schema_version',
        'event_type',
        'severity',
        'risk_score',
        'actor_user_id',
        'tenant_id',
        'access_session_id',
        'support_session_id',
        'source_ip',
        'resource_type',
        'resource_id',
        'action',
        'authorization_result',
        'reason_code',
        'request_id',
        'correlation_id',
        'detected_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'metadata' => 'array',
            'risk_score' => 'integer',
            'event_schema_version' => 'integer',
        ];
    }
}
