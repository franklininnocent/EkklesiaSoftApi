<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChurchSocialMedia Model
 * 
 * Normalized storage for church social media accounts.
 * Supports multiple accounts per platform with primary designation.
 */
class ChurchSocialMedia extends Model
{
    use HasFactory;

    protected $table = 'church_social_media';

    protected $fillable = [
        'tenant_id',
        'platform',
        'url',
        'username',
        'follower_count',
        'is_primary',
        'display_order',
        'active',
    ];

    protected $casts = [
        'follower_count' => 'integer',
        'is_primary' => 'integer',
        'display_order' => 'integer',
        'active' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Supported social media platforms
     */
    const PLATFORM_FACEBOOK = 'facebook';
    const PLATFORM_TWITTER = 'twitter';
    const PLATFORM_INSTAGRAM = 'instagram';
    const PLATFORM_YOUTUBE = 'youtube';
    const PLATFORM_LINKEDIN = 'linkedin';
    const PLATFORM_TIKTOK = 'tiktok';
    const PLATFORM_WHATSAPP = 'whatsapp';

    /**
     * Get the tenant/church this social media belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope: Get only active social media accounts
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Scope: Get primary accounts only
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', 1);
    }

    /**
     * Scope: Filter by platform
     */
    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope: Order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('platform');
    }

    /**
     * Get icon class for the platform
     */
    public function getPlatformIconAttribute(): string
    {
        $icons = [
            'facebook' => 'fab fa-facebook',
            'twitter' => 'fab fa-twitter',
            'instagram' => 'fab fa-instagram',
            'youtube' => 'fab fa-youtube',
            'linkedin' => 'fab fa-linkedin',
            'tiktok' => 'fab fa-tiktok',
            'whatsapp' => 'fab fa-whatsapp',
        ];

        return $icons[$this->platform] ?? 'fas fa-link';
    }

    /**
     * Get color class for the platform
     */
    public function getPlatformColorAttribute(): string
    {
        $colors = [
            'facebook' => '#1877f2',
            'twitter' => '#1da1f2',
            'instagram' => '#e4405f',
            'youtube' => '#ff0000',
            'linkedin' => '#0077b5',
            'tiktok' => '#000000',
            'whatsapp' => '#25d366',
        ];

        return $colors[$this->platform] ?? '#6c757d';
    }
}
