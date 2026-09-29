<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MassCelebration extends Model
{
    use HasUuids;

    protected $table = 'mass_celebrations';

    protected $fillable = [
        'tenant_id',
        'celebrated_on',
        'celebrated_at',
        'place',
        'celebrant_name',
        'celebrant_leadership_assignment_id',
        'status',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'celebrated_on' => 'date',
        ];
    }
}
