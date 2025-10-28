<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class EcclesiasticalDataQuality extends Model
{
    protected $table = 'ecclesiastical_data_quality';
    
    public $incrementing = false;
    
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'quality_flag',
        'severity',
        'issue_description',
        'recommended_action',
        'is_resolved',
        'flagged_by',
        'resolved_by',
        'flagged_at',
        'resolved_at',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
        'flagged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = Str::uuid();
            }
            if (!$model->flagged_at) {
                $model->flagged_at = now();
            }
        });
    }

    /**
     * Get the user who flagged the issue
     */
    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    /**
     * Get the user who resolved the issue
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope for unresolved issues
     */
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    /**
     * Scope for critical issues
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope for specific entity
     */
    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }
}

