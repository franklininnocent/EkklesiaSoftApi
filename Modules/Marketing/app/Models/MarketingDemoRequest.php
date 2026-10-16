<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingDemoRequest extends Model
{
    protected $table = 'marketing_demo_requests';

    protected $fillable = [
        'name',
        'church_parish',
        'diocese_organization',
        'country',
        'email',
        'phone',
        'role',
        'message',
            'correlation_id',
            'source_ip_hash',
        ];
}
