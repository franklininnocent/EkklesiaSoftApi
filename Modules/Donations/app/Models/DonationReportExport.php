<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationReportExport extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'donation_report_exports';

    protected $fillable = [
        'tenant_id',
        'report_type',
        'export_format',
        'filters',
        'filter_hash',
        'file_path',
        'file_size',
        'row_count',
        'status',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
        'requested_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
