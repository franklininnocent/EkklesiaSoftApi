<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassIntentionTransfer extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_transfers';

    protected $fillable = [
        'from_tenant_id',
        'to_tenant_id',
        'request_id',
        'status',
        'note',
        'initiated_by_user_id',
        'responded_by_user_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MassIntentionRequest::class, 'request_id');
    }
}
