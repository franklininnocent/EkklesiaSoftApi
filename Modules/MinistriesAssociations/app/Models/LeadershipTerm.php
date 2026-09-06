<?php

namespace Modules\MinistriesAssociations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\MinistriesAssociations\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class LeadershipTerm extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VACATED = 'vacated';

    public const STATUS_TERMINATED = 'terminated';

    public const EXIT_REASON_RESIGNED = 'resigned';

    public const EXIT_REASON_TRANSFERRED = 'transferred';

    public const EXIT_REASON_REMOVED = 'removed';

    public const EXIT_REASON_TERM_COMPLETED = 'term_completed';

    public const EXIT_REASON_DECEASED = 'deceased';

    public const EXIT_REASON_CENSUS_CASCADE = 'census_cascade';

    public const EXIT_REASON_MEMBERSHIP_STATUS_CHANGE = 'membership_status_change';

    protected $table = 'ma_leadership_terms';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'membership_id',
        'position_id',
        'appointment_date',
        'effective_from',
        'effective_to',
        'term_label',
        'appointment_reference',
        'is_interim',
        'status',
        'exit_reason',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_interim' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
