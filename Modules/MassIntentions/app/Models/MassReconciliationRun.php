<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassReconciliationRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'mass_reconciliation_runs';

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'fingerprint',
        'range_from',
        'range_to',
        'counts',
        'conflict_count',
    ];

    protected function casts(): array
    {
        return [
            'range_from' => 'date',
            'range_to' => 'date',
            'counts' => 'array',
        ];
    }
}
