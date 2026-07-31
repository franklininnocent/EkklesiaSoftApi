<?php

namespace Modules\BCC\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Family\Models\FamilyMember;
use App\Models\User;
use Modules\BCC\Database\Factories\BCCLeaderFactory;

class BCCLeader extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return BCCLeaderFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bcc_leaders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'bcc_id',
        'family_member_id',
        'role',
        'role_description',
        'appointed_date',
        'term_start_date',
        'term_end_date',
        'is_active',
        'leader_phone',
        'leader_email',
        'responsibilities',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'appointed_date' => 'date',
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['is_term_expired', 'days_in_role'];

    /**
     * Get the BCC that this leader serves.
     */
    public function bcc(): BelongsTo
    {
        return $this->belongsTo(BCC::class);
    }

    /**
     * Get the family member who is the leader.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'family_member_id');
    }

    /**
     * Get the user who created the leader record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the leader record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active leaders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by role.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope a query to filter by BCC.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $bccId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForBCC($query, string $bccId)
    {
        return $query->where('bcc_id', $bccId);
    }

    /**
     * Check if the leader's term has expired.
     *
     * @return bool
     */
    public function getIsTermExpiredAttribute(): bool
    {
        if (!$this->term_end_date) {
            return false;
        }

        return $this->term_end_date->isPast();
    }

    /**
     * Get the number of days in the role.
     *
     * @return int|null
     */
    public function getDaysInRoleAttribute(): ?int
    {
        if (!$this->appointed_date) {
            return null;
        }

        return $this->appointed_date->diffInDays(now());
    }

    /**
     * Get the leader's full name through relationship.
     *
     * @return string|null
     */
    public function getLeaderNameAttribute(): ?string
    {
        return $this->member?->full_name_display;
    }

    /**
     * Get the contact phone (override or member's phone).
     *
     * @return string|null
     */
    public function getContactPhoneAttribute(): ?string
    {
        return $this->leader_phone ?? $this->member?->phone;
    }

    /**
     * Get the contact email (override or member's email).
     *
     * @return string|null
     */
    public function getContactEmailAttribute(): ?string
    {
        return $this->leader_email ?? $this->member?->email;
    }

    /**
     * Deactivate this leadership role.
     *
     * @return void
     */
    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();
    }

    /**
     * Activate this leadership role.
     *
     * @return void
     */
    public function activate(): void
    {
        $this->is_active = true;
        $this->save();
    }

    /**
     * Check if term is about to expire (within 30 days).
     *
     * @return bool
     */
    public function isTermExpiringSoon(): bool
    {
        if (!$this->term_end_date) {
            return false;
        }

        return $this->term_end_date->isFuture() && 
               $this->term_end_date->diffInDays(now()) <= 30;
    }
}


