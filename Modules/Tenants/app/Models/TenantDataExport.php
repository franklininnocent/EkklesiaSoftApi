<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Concerns\BelongsToTenant;

class TenantDataExport extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'tenant_data_exports';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'modules',
        'options',
        'status',
        'progress',
        'record_counts',
        'file_path',
        'file_size',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
        'download_count',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'modules' => 'array',
        'options' => 'array',
        'progress' => 'array',
        'record_counts' => 'array',
        'file_size' => 'integer',
        'download_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isDownloadable(): bool
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return filled($this->file_path);
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->status === self::STATUS_COMPLETED
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
