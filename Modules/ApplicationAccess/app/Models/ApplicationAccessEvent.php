<?php

namespace Modules\ApplicationAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationAccessEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'application_access_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'event_schema_version',
        'access_session_id',
        'user_id',
        'ip_address',
        'event_type',
        'module_code',
        'feature_code',
        'resource_type',
        'resource_id',
        'action',
        'route_name',
        'normalized_route',
        'http_method',
        'http_status',
        'authorization_result',
        'permission_code',
        'tenant_id',
        'support_session_id',
        'request_id',
        'correlation_id',
        'source_sequence',
        'telemetry_source',
        'occurred_at',
        'risk_level',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'event_schema_version' => 'integer',
            'http_status' => 'integer',
            'source_sequence' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ApplicationAccessSession::class, 'access_session_id');
    }
}
