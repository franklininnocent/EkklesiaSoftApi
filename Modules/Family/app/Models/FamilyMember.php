<?php

namespace Modules\Family\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use Modules\Family\Database\Factories\FamilyMemberFactory;

class FamilyMember extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return FamilyMemberFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'family_members';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'family_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'relationship_to_head',
        'marital_status',
        'phone',
        'email',
        'is_primary_contact',
        'baptism_date',
        'baptism_place',
        'first_communion_date',
        'first_communion_place',
        'confirmation_date',
        'confirmation_place',
        'marriage_date',
        'marriage_place',
        'marriage_spouse_name',
        'occupation',
        'education',
        'skills_talents',
        'notes',
        'status',
        'deceased_date',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'baptism_date' => 'date',
        'first_communion_date' => 'date',
        'confirmation_date' => 'date',
        'marriage_date' => 'date',
        'deceased_date' => 'date',
        'is_primary_contact' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['age', 'full_name_display'];

    /**
     * Get the family that this member belongs to.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get BCC leadership roles for this member.
     */
    public function bccLeaderships(): HasMany
    {
        return $this->hasMany(\Modules\BCC\Models\BCCLeader::class, 'family_member_id');
    }

    /**
     * Get active BCC leadership roles for this member.
     */
    public function activeBccLeaderships(): HasMany
    {
        return $this->hasMany(\Modules\BCC\Models\BCCLeader::class, 'family_member_id')
            ->where('is_active', true);
    }

    /**
     * Get the user who created the member.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the member.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active members.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to filter by gender.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $gender
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGender($query, string $gender)
    {
        return $query->where('gender', $gender);
    }

    /**
     * Scope a query to filter by relationship.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $relationship
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRelationship($query, string $relationship)
    {
        return $query->where('relationship_to_head', $relationship);
    }

    /**
     * Scope a query to include baptized members.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBaptized($query)
    {
        return $query->whereNotNull('baptism_date');
    }

    /**
     * Scope a query to include confirmed members.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmation_date');
    }

    /**
     * Get the member's age.
     *
     * @return int|null
     */
    public function getAgeAttribute(): ?int
    {
        if (!$this->date_of_birth) {
            return null;
        }

        return $this->date_of_birth->age;
    }

    /**
     * Get the member's full name.
     *
     * @return string
     */
    public function getFullNameDisplayAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Check if the member is baptized.
     *
     * @return bool
     */
    public function isBaptized(): bool
    {
        return !is_null($this->baptism_date);
    }

    /**
     * Check if the member is confirmed.
     *
     * @return bool
     */
    public function isConfirmed(): bool
    {
        return !is_null($this->confirmation_date);
    }

    /**
     * Check if the member is married.
     *
     * @return bool
     */
    public function isMarried(): bool
    {
        return $this->marital_status === 'married';
    }

    /**
     * Check if the member is a BCC leader.
     *
     * @return bool
     */
    public function isBCCLeader(): bool
    {
        return $this->activeBccLeaderships()->exists();
    }
}


