<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class MarketingPlanCatalogRevision extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'marketing_plan_catalog_revisions';

    protected $fillable = [
        'revision_number',
        'status',
        'payload',
        'eligible_payload',
        'feature_visibility',
        'source_refs',
        'source_fingerprint',
        'payload_hash',
        'validation_report',
        'generated_by',
        'generated_at',
        'published_by',
        'published_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'eligible_payload' => 'array',
            'feature_visibility' => 'array',
            'source_refs' => 'array',
            'validation_report' => 'array',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
