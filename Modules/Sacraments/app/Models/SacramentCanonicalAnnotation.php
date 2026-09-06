<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\Sacraments\Support\SacramentAnnotationType;
use Modules\Tenants\Models\Tenant;

class SacramentCanonicalAnnotation extends Model
{
    use SoftDeletes;

    protected $table = 'sacrament_canonical_annotations';

    protected $appends = [
        'annotation_type_label',
    ];

    protected $fillable = [
        'tenant_id',
        'sacrament_id',
        'annotation_type',
        'effective_date',
        'granting_authority',
        'protocol_number',
        'notes',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function annotationTypeLabel(): Attribute
    {
        return Attribute::get(
            fn () => SacramentAnnotationType::label($this->annotation_type)
        );
    }
}
