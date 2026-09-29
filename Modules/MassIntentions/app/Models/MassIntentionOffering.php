<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MassIntentionOffering extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_offerings';

    protected $fillable = [
        'tenant_id',
        'request_id',
        'currency_code',
        'status',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MassIntentionRequest::class, 'request_id');
    }
}
