<?php

namespace Modules\Family\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdTransition extends Model
{
    use HasUuids;

    public const STATUS_COMPLETED = 'completed';

    public const TYPE_RELOCATION = 'RELOCATION';

    public const TYPE_MARRIAGE = 'MARRIAGE';

    public const TYPE_SPLIT = 'SPLIT';

    public const TYPE_ADMIN_CORRECTION = 'ADMIN_CORRECTION';

    protected $table = 'household_transitions';

    protected $fillable = [
        'tenant_id',
        'transition_id',
        'type',
        'status',
        'request_hash',
        'result_summary',
        'performed_by_user_id',
    ];

    protected $casts = [
        'result_summary' => 'array',
    ];

    public function scopeForTenant($query, int|string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
