<?php

namespace Modules\BCC\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class BccFamilyMembership extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXITED = 'exited';

    protected $table = 'bcc_family_memberships';

    protected $fillable = [
        'tenant_id',
        'bcc_id',
        'family_id',
        'status',
        'joined_date',
        'exit_date',
        'exit_reason',
        'notes',
        'is_current',
        'created_by',
        'updated_by',
        'historical_note',
        'transferred_by_user_id',
        'transition_id',
        'transfer_reason',
    ];

    protected $casts = [
        'joined_date' => 'date',
        'exit_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class, 'bcc_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true)->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForBcc($query, string $bccId)
    {
        return $query->where('bcc_id', $bccId);
    }
}
