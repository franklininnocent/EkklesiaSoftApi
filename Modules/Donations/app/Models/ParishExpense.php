<?php

namespace Modules\Donations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Donations\Models\Concerns\BelongsToTenant;

class ParishExpense extends Model
{
    use HasUuids, SoftDeletes, BelongsToTenant;

    protected $table = 'parish_expenses';

    protected $fillable = [
        'tenant_id',
        'category',
        'amount',
        'currency',
        'expense_date',
        'payee',
        'method',
        'notes',
        'status',
        'recorded_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];
}
