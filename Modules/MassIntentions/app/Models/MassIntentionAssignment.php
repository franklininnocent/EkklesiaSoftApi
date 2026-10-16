<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassIntentionAssignment extends Model
{
    use HasUuids;

    protected $table = 'mass_intention_assignments';

    protected $fillable = [
        'tenant_id',
        'obligation_id',
        'celebration_id',
        'assigned_at',
        'unassigned_at',
        'assigned_by_user_id',
        'date_variance_reason',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }
}
