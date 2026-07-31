<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationReportExport extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_report_exports';

    protected $fillable = [
        'tenant_id',
        'report_type',
        'filters',
        'file_path',
        'status',
        'error_message',
        'requested_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'filters' => 'array',
    ];
}
