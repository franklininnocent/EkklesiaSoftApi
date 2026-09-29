<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassIntentionObligation extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_obligations';

    protected $fillable = [
        'tenant_id',
        'request_id',
        'sequence',
        'status',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MassIntentionRequest::class, 'request_id');
    }
}
