<?php

namespace Modules\ApplicationAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Authentication\Models\User;

class ApplicationAccessSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'application_access_sessions';

    protected $fillable = [
        'session_reference',
        'oauth_access_token_id',
        'previous_session_id',
        'user_id',
        'tenant_id',
        'role_id',
        'support_session_id',
        'identity_type',
        'access_context',
        'authentication_status',
        'status',
        'started_at',
        'last_activity_at',
        'ended_at',
        'end_reason',
        'ip_address',
        'ip_version',
        'ip_class',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'timezone',
        'geo_source',
        'geo_status',
        'browser',
        'browser_version',
        'operating_system',
        'device_type',
        'user_agent',
        'risk_level',
        'risk_score',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'risk_score' => 'integer',
            'ip_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationAccessEvent::class, 'access_session_id');
    }
}
