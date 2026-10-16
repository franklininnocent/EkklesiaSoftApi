<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactEnquiryDeliveryEvent extends Model
{
    protected $table = 'contact_enquiry_delivery_events';

    protected $fillable = [
        'contact_enquiry_id',
        'status',
        'attempt',
        'detail',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ContactEnquiry::class, 'contact_enquiry_id');
    }
}
