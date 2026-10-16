<?php

namespace Modules\MinistriesAssociations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Models\Concerns\BelongsToTenant;
use Modules\Tenants\Models\Tenant;

class OrganizationMembership extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const SOURCE_PARISH = 'parish';

    public const SOURCE_GUEST = 'guest';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_RESIGNED = 'resigned';

    public const STATUS_EXITED = 'exited';

    public const STATUS_DECEASED = 'deceased';

    protected $table = 'ma_memberships';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'member_source',
        'family_member_id',
        'guest_member_id',
        'member_type',
        'status',
        'joined_date',
        'exit_date',
        'exit_reason',
        'remarks',
        'emergency_contact',
        'is_current',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'joined_date' => 'date',
        'exit_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'family_member_id');
    }

    public function guestMember(): BelongsTo
    {
        return $this->belongsTo(GuestMember::class, 'guest_member_id');
    }

    public function leadershipTerms(): HasMany
    {
        return $this->hasMany(LeadershipTerm::class, 'membership_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeParish($query)
    {
        return $query->where('member_source', self::SOURCE_PARISH);
    }

    public function scopeGuest($query)
    {
        return $query->where('member_source', self::SOURCE_GUEST);
    }

    public function isParishMember(): bool
    {
        return $this->member_source === self::SOURCE_PARISH;
    }

    public function isGuestMember(): bool
    {
        return $this->member_source === self::SOURCE_GUEST;
    }
}
