<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

class DonationNumberSequence extends Model
{
    protected $table = 'donation_number_sequences';

    protected $fillable = [
        'tenant_id',
        'kind',
        'period',
        'last_value',
    ];
}
