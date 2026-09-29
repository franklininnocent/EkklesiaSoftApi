<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassIntentionFulfilment extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_fulfilments';

    protected $fillable = [
        'tenant_id',
        'obligation_id',
        'celebration_id',
        'fulfilled_at',
        'fulfilled_by_user_id',
        'celebrant_override',
        'undone_at',
        'undo_reason',
    ];

    protected function casts(): array
    {
        return [
            'fulfilled_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }
}
