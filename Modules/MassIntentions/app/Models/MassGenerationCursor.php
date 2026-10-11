<?php

namespace Modules\MassIntentions\Models;

use Illuminate\Database\Eloquent\Model;

class MassGenerationCursor extends Model
{
    protected $table = 'mass_generation_cursors';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'last_generated_through',
        'last_success_at',
        'last_error',
        'last_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'last_generated_through' => 'date',
            'last_success_at' => 'datetime',
        ];
    }
}
