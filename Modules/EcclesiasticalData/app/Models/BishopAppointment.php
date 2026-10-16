<?php

namespace Modules\EcclesiasticalData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\AppointmentStatus;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Models\EcclesiasticalTitle;

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
        'canonical_role',
        'appointed_date',
        'announced_date',
        'effective_date',
        'ordained_date',
        'installed_date',
        'ended_date',
        'end_reason',
        'is_current',
        'appointment_status',
        'appointment_details',
        'metadata',
        'version',
        'source_type',
        'source_reference',
        'verified_by',
        'verified_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'appointed_date' => 'date',
        'announced_date' => 'date',
        'effective_date' => 'date',
        'ordained_date' => 'date',
        'installed_date' => 'date',
        'ended_date' => 'date',
        'is_current' => 'boolean',
        'metadata' => 'array',
        'canonical_role' => CanonicalRole::class,
        'appointment_status' => AppointmentStatus::class,
        'end_reason' => AppointmentEndReason::class,
        'verified_at' => 'datetime',
        'version' => 'integer',
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

    /**
     * Scope for ordinary (diocesan) leadership roles.
     */
    public function scopeOrdinary($query)
    {
        return $query->whereIn('canonical_role', CanonicalRole::ordinaryRoles());
    }

    public function isOrdinaryRole(): bool
    {
        return $this->canonical_role instanceof CanonicalRole
            ? $this->canonical_role->isOrdinary()
            : in_array($this->canonical_role, CanonicalRole::ordinaryRoles(), true);
    }
}

