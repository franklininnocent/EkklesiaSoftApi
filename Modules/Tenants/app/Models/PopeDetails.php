<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;

/**
 * PopeDetails Model
 * 
 * Global Pope information - not tenant-specific.
 * There is only one active Pope globally at any time.
 */
class PopeDetails extends Model
{
    use HasFactory;

    protected $table = 'pope_details';

    protected $fillable = [
        'pope_name',
        'pope_image_path',
        'pope_title',
        'pope_effective_from',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'pope_effective_from' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache key for current pope details
     */
    private const CACHE_KEY = 'pope_details_current';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get the current active pope details
     * Since there's only one global pope, we'll always get the first/latest record
     * Results are cached for 1 hour to improve performance
     */
    public static function getCurrent(): ?self
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Use limit(1) for better performance and ensure we only get one record
            return self::orderBy('updated_at', 'desc')
                ->limit(1)
                ->first();
        });
    }

    /**
     * Clear the cache for current pope details
     * Should be called after any update/create/delete operations
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Boot method to clear cache on model events
     */
    protected static function boot()
    {
        parent::boot();

        // Clear cache when pope details are saved
        static::saved(function () {
            self::clearCache();
        });

        // Clear cache when pope details are deleted
        static::deleted(function () {
            self::clearCache();
        });
    }
}


