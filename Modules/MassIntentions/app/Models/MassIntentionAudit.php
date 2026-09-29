<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassIntentionAudit extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'mass_intention_audits';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'request_id',
        'celebration_id',
        'actor_user_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
