<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PopeAssignment extends Model
{
    use HasUuids;

    protected $table = 'pope_assignments';

    protected $fillable = [
        'pope_name',
        'pope_title',
        'photo_path',
        'start_date',
        'end_date',
        'status',
        'appointment_reference',
        'change_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->whereNull('end_date');
    }
}
