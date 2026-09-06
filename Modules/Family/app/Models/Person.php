<?php

namespace Modules\Family\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Family\Database\Factories\PersonFactory;
use Modules\Tenants\Models\Tenant;

class Person extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'persons';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'place_of_birth',
        'gender',
        'father_name',
        'mother_name',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'postal_code',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['full_name_display'];

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'person_id');
    }

    public function activeFamilyMember(): HasOne
    {
        return $this->hasOne(FamilyMember::class, 'person_id')->whereNull('deleted_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function linkedUser(): HasOne
    {
        return $this->hasOne(User::class, 'person_id');
    }

    public function scopeForTenant(Builder $query, int|string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function getFullNameDisplayAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}
