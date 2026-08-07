<?php

namespace Modules\SupportAccess\Models;

use Illuminate\Database\Eloquent\Model;

class SupportSessionSetting extends Model
{
    protected $table = 'support_session_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
