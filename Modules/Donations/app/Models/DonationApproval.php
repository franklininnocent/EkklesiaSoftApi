<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationApproval extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_approvals';

    protected $fillable = [
        'tenant_id',
        'target_type',
        'target_id',
        'action',
        'status',
        'reason',
        'requested_by',
        'decided_by',
        'decided_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];
}
