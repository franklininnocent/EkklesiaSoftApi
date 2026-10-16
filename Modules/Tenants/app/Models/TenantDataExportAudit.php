<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;

class TenantDataExportAudit extends Model
{
    protected $table = 'tenant_data_export_audits';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'support_session_id',
        'event',
        'target_type',
        'target_id',
        'old_values',
        'new_values',
        'metadata',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];
}
