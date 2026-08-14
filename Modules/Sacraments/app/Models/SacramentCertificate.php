<?php

namespace Modules\Sacraments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

/**
 * Sacrament certificate issuance record (ADR-09). Populated in Phase 8.
 */
class SacramentCertificate extends Model
{
    use SoftDeletes;

    protected $table = 'sacrament_certificates';

    protected $fillable = [
        'tenant_id',
        'sacrament_id',
        'certificate_number',
        'certificate_type',
        'status',
        'version',
        'language',
        'locale',
        'template_code',
        'template_version',
        'issued_at',
        'issued_by',
        'storage_key',
        'checksum',
        'mime_type',
        'size_bytes',
        'projection_json',
        'supersedes_certificate_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'size_bytes' => 'integer',
        'issued_at' => 'datetime',
        'projection_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft_preview',
        'version' => 1,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sacrament(): BelongsTo
    {
        return $this->belongsTo(Sacrament::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_certificate_id');
    }
}
