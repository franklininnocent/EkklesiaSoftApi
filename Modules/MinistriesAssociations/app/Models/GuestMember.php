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

class GuestMember extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const GUEST_TYPE_SUPPORTER = 'supporter';

    public const GUEST_TYPE_VOLUNTEER = 'volunteer';

    public const GUEST_TYPE_BENEFACTOR = 'benefactor';

    public const GUEST_TYPE_ADVISOR = 'advisor';

    public const GUEST_TYPE_RESOURCE_PERSON = 'resource_person';

    protected $table = 'ma_guest_members';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'gender',
        'phone',
        'email',
        'address',
        'guest_type',
        'external_organization',
        'support_type',
        'remarks',
        'linked_family_member_id',
        'created_by',
        'updated_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function linkedFamilyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'linked_family_member_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'guest_member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
