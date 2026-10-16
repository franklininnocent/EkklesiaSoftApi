<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Marketing\Database\Factories\ContactEnquiryFactory;

class ContactEnquiry extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected static function newFactory(): ContactEnquiryFactory
    {
        return ContactEnquiryFactory::new();
    }

    public const STATUS_UNREAD = 'unread';

    public const STATUS_READ = 'read';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

    public const DELIVERY_RECEIVED = 'received';

    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    protected $table = 'contact_enquiries';

    protected $fillable = [
        'reference_number',
        'full_name',
        'email',
        'phone',
        'organisation',
        'subject',
        'message',
        'enquiry_status',
        'delivery_status',
        'delivery_attempts',
        'last_delivered_at',
        'last_delivery_error',
        'correlation_id',
        'source_ip_hash',
        'read_at',
        'read_by',
    ];

    protected $casts = [
        'delivery_attempts' => 'integer',
        'last_delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function deliveryEvents(): HasMany
    {
        return $this->hasMany(ContactEnquiryDeliveryEvent::class, 'contact_enquiry_id');
    }

    public static function enquiryStatuses(): array
    {
        return [
            self::STATUS_UNREAD,
            self::STATUS_READ,
            self::STATUS_IN_PROGRESS,
            self::STATUS_CLOSED,
            self::STATUS_ARCHIVED,
        ];
    }

    public static function deliveryStatuses(): array
    {
        return [
            self::DELIVERY_RECEIVED,
            self::DELIVERY_QUEUED,
            self::DELIVERY_SENT,
            self::DELIVERY_FAILED,
        ];
    }
}
