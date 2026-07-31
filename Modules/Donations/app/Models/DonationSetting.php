<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class DonationSetting extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'donation_settings';

    protected $fillable = [
        'tenant_id',
        'default_currency',
        'financial_year_start_month',
        'financial_year_start_day',
        'tax_registration_number',
        'tax_acknowledgement_note',
        'receipt_prefix_enabled',
        'receipt_prefix',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'receipt_prefix_enabled' => 'boolean',
        'metadata' => 'array',
    ];
}
