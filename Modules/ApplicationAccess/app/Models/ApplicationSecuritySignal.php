<?php

namespace Modules\ApplicationAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApplicationSecuritySignal extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'application_security_signals';

    protected $fillable = [
        'id',
        'signal_type',
        'source_ip',
        'window_start',
        'window_end',
        'event_count',
        'unique_routes',
        'status_counts',
        'first_seen',
        'last_seen',
        'risk_level',
        'risk_score',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'unique_routes' => 'array',
            'status_counts' => 'array',
            'event_count' => 'integer',
            'risk_score' => 'integer',
        ];
    }
}
