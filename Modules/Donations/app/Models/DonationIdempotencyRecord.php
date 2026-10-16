<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DonationIdempotencyRecord extends Model
{
    use HasUuids;

    protected $table = 'donation_idempotency_records';

    protected $fillable = [
        'tenant_id',
        'operation',
        'key',
        'payload_hash',
        'resource_type',
        'resource_id',
        'http_status',
        'response_body',
    ];

    protected $casts = [
        'response_body' => 'array',
        'http_status' => 'integer',
    ];
}
