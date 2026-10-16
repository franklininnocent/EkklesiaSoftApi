<?php

namespace Modules\Family\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenants\Models\Tenant;

class PersonIdentityReconciliationLog extends Model
{
    use HasUuids;

    protected $table = 'person_identity_reconciliation_logs';

    protected $fillable = [
        'tenant_id',
        'person_id',
        'field',
        'old_value',
        'new_value',
        'source_selected',
        'reason',
        'user_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
