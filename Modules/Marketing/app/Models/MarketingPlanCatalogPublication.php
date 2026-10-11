<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

class MarketingPlanCatalogPublication extends Model
{
    public const REVALIDATE_NOT_REQUIRED = 'not_required';

    public const REVALIDATE_SUCCEEDED = 'succeeded';

    public const REVALIDATE_FAILED = 'failed';

    public const REVALIDATE_SKIPPED = 'skipped';

    protected $table = 'marketing_plan_catalog_publication';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'published_revision_id',
        'published_at',
        'published_by',
        'revalidate_status',
        'revalidate_error',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(MarketingPlanCatalogRevision::class, 'published_revision_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
