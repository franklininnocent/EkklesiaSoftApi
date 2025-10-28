<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Tenants\Models\EcclesiasticalTitle;

class BishopAppointment extends Model
{
    use SoftDeletes;

    protected $table = 'bishop_appointments';
    
    public $incrementing = false;
    
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'bishop_id',
        'diocese_id',
        'ecclesiastical_title_id',
        'appointed_date',
        'ordained_date',
        'installed_date',
        'ended_date',
        'end_reason',
        'is_current',
        'appointment_details',
        'metadata',
    ];

    protected $casts = [
        'appointed_date' => 'date',
        'ordained_date' => 'date',
        'installed_date' => 'date',
        'ended_date' => 'date',
        'is_current' => 'boolean',
        'metadata' => 'array',
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
     * Get the bishop
     */
    public function bishop(): BelongsTo
    {
        return $this->belongsTo(BishopManagement::class, 'bishop_id');
    }

    /**
     * Get the diocese
     */
    public function diocese(): BelongsTo
    {
        return $this->belongsTo(DioceseManagement::class, 'diocese_id');
    }

    /**
     * Get the ecclesiastical title
     */
    public function ecclesiasticalTitle(): BelongsTo
    {
        return $this->belongsTo(EcclesiasticalTitle::class);
    }

    /**
     * Scope for current appointments
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope for active appointments (not ended)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('ended_date');
    }
}

