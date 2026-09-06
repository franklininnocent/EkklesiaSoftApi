<?php

namespace Modules\Family\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMemberHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'family_member_histories';

    protected $fillable = [
        'tenant_id',
        'transition_id',
        'member_id',
        'from_family_id',
        'to_family_id',
        'previous_family_role',
        'new_family_role',
        'transition_type',
        'effective_date',
        'performed_by_user_id',
        'corrects_history_id',
        'metadata',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeForTenant($query, int|string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'member_id');
    }

    public function fromFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'from_family_id');
    }

    public function toFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'to_family_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_history_id');
    }
}
