<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DiocesesHierarchy extends Model
{
    use SoftDeletes;

    protected $table = 'diocese_hierarchy';
    
    public $incrementing = false;
    
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'metropolitan_id',
        'suffragan_id',
        'effective_from',
        'effective_until',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = Str::uuid();
            }
        });
    }

    /**
     * Get the metropolitan (archdiocese)
     */
    public function metropolitan(): BelongsTo
    {
        return $this->belongsTo(DioceseManagement::class, 'metropolitan_id');
    }

    /**
     * Get the suffragan (diocese)
     */
    public function suffragan(): BelongsTo
    {
        return $this->belongsTo(DioceseManagement::class, 'suffragan_id');
    }

    /**
     * Scope for active relationships
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', now());
            });
    }
}

