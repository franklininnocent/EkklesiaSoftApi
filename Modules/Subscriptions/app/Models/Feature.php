<?php

namespace Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    public const TYPE_BOOLEAN = 'BOOLEAN';

    public const TYPE_LIMIT = 'LIMIT';

    public const TYPE_QUOTA = 'QUOTA';

    public const TYPE_TIER = 'TIER';

    public const TYPE_MODULE = 'MODULE';

    public const TYPE_USAGE = 'USAGE';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BOOLEAN,
        self::TYPE_LIMIT,
        self::TYPE_QUOTA,
        self::TYPE_TIER,
        self::TYPE_MODULE,
        self::TYPE_USAGE,
    ];

    /** @var list<string> */
    public const NUMERIC_TYPES = [self::TYPE_LIMIT, self::TYPE_QUOTA, self::TYPE_USAGE];

    protected $table = 'features';

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'module_key',
        'feature_type',
        'unit',
        'legacy_key',
        'is_core',
        'legacy_default',
        'is_public',
        'is_active',
        'display_order',
        'tier_options',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'legacy_default' => 'boolean',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'tier_options' => 'array',
        'metadata' => 'array',
    ];

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'feature_dependencies', 'feature_id', 'depends_on_feature_id')
            ->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'feature_dependencies', 'depends_on_feature_id', 'feature_id')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('category')->orderBy('display_order')->orderBy('name');
    }

    public function isNumeric(): bool
    {
        return in_array($this->feature_type, self::NUMERIC_TYPES, true);
    }
}
