<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class WebSettingsAuditLog extends Model
{
    protected $table = 'web_settings_audit_logs';

    protected $fillable = [
        'actor_id',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];
}
