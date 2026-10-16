<?php

namespace Modules\MinistriesAssociations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\MinistriesAssociations\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class Organization extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'ma_organizations';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'type_id',
        'code',
        'name',
        'short_name',
        'description',
        'vision',
        'mission',
        'objectives',
        'patron_saint',
        'established_date',
        'theme_color',
        'email',
        'phone',
        'website',
        'social_links',
        'status',
        'allow_multi_role_holding',
        'guests_can_hold_office',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'established_date' => 'date',
        'social_links' => 'array',
        'allow_multi_role_holding' => 'boolean',
        'guests_can_hold_office' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OrganizationCategory::class, 'category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'type_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'organization_id');
    }

    public function currentMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'organization_id')
            ->where('is_current', true);
    }

    public function leadershipTerms(): HasMany
    {
        return $this->hasMany(LeadershipTerm::class, 'organization_id');
    }

    public function activeLeadershipTerms(): HasMany
    {
        return $this->hasMany(LeadershipTerm::class, 'organization_id')
            ->where('status', LeadershipTerm::STATUS_ACTIVE);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }
}
