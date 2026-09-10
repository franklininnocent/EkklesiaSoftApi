<?php

namespace Modules\EcclesiasticalData\Models;

use Modules\Tenants\Models\Bishop;
use Modules\Tenants\Models\Archdiocese;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Modules\EcclesiasticalData\Traits\HasAuditTrail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Support\CaseInsensitiveSearch;

/**
 * Platform master-data bishop person record.
 *
 * Diocese assignment history lives in bishop_appointments; archdiocese_id on
 * bishops is retained for backward compatibility during the succession rollout.
 */

class BishopManagement extends Bishop
{
    use HasFactory, HasAuditTrail;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Modules\EcclesiasticalData\Database\Factories\BishopManagementFactory::new();
    }

    /**
     * Get fillable attributes including parent
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), [
            'normalized_name',
            'given_name',
            'family_name',
            'religious_name',
            'ecclesiastical_title_id',
            'date_of_birth',
            'ordained_priest_date',
            'ordained_bishop_date',
            'retired_date',
            'status',
            'is_current',
            'precedence_order',
            'is_active',
            'education',
            'metadata',
            'last_verified_at',
            'last_verified_by',
            'verification_notes',
            'photo_path',
            'coat_of_arms_path',
        ]);
    }

    /**
     * Get casts including parent
     */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), [
            'date_of_birth' => 'date',
            'ordained_priest_date' => 'date',
            'ordained_bishop_date' => 'date',
            'retired_date' => 'date',
            'metadata' => 'array',
            'last_verified_at' => 'datetime',
            'is_current' => 'boolean',
        ]);
    }

    /**
     * Get the ecclesiastical title
     */
    public function ecclesiasticalTitle(): BelongsTo
    {
        return $this->belongsTo(EcclesiasticalTitle::class, 'ecclesiastical_title_id');
    }

    /**
     * Get the religious order
     */
    public function religiousOrder(): BelongsTo
    {
        return $this->belongsTo(ReligiousOrder::class, 'religious_order_id');
    }

    /**
     * Get the country (birth country)
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'birth_country_id');
    }

    /**
     * Get the state (birth state)
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'birth_state_id');
    }

    /**
     * Get the nationality country
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_country_id');
    }

    /**
     * Override the archdiocese relationship from parent
     */
    public function archdiocese(): BelongsTo
    {
        return $this->belongsTo(Archdiocese::class, 'archdiocese_id');
    }

    /**
     * Get all appointments for this bishop
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(BishopAppointment::class, 'bishop_id');
    }

    /**
     * Get current appointment
     */
    public function currentAppointment(): ?BishopAppointment
    {
        if ($this->relationLoaded('appointments')) {
            return $this->appointments
                ->where('is_current', true)
                ->sortByDesc(fn (BishopAppointment $appointment) => $appointment->effective_date?->timestamp ?? 0)
                ->first();
        }

        return $this->appointments()
            ->where('is_current', true)
            ->with(['diocese', 'ecclesiasticalTitle'])
            ->orderByDesc('effective_date')
            ->first();
    }

    public function updateRequests(): HasMany
    {
        return $this->hasMany(BishopUpdateRequest::class, 'target_bishop_id');
    }

    /**
     * Get data quality issues
     */
    public function qualityIssues(): HasMany
    {
        return $this->hasMany(EcclesiasticalDataQuality::class, 'entity_id')
            ->where('entity_type', 'bishops');
    }

    /**
     * Scope for active bishops
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for search
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        $pattern = '%'.$search.'%';
        $matchingDioceseIds = $this->matchingDioceseIds($pattern);

        return $query->where(function ($q) use ($pattern, $matchingDioceseIds) {
            CaseInsensitiveSearch::applyColumnLike($q, 'full_name', $pattern);
            CaseInsensitiveSearch::applyColumnLike($q, 'given_name', $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($q, 'family_name', $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($q, 'religious_name', $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($q, 'email', $pattern, 'or');

            if ($matchingDioceseIds->isNotEmpty()) {
                $table = $this->getTable();

                $q->orWhereIn("{$table}.archdiocese_id", $matchingDioceseIds)
                    ->orWhereIn("{$table}.id", BishopAppointment::query()
                        ->select('bishop_id')
                        ->whereIn('diocese_id', $matchingDioceseIds)
                        ->whereNull('deleted_at'));
            }
        });
    }

    /**
     * Resolve diocese IDs whose name/code matches the search pattern.
     *
     * @return \Illuminate\Support\Collection<int, int|string>
     */
    protected function matchingDioceseIds(string $pattern)
    {
        return Archdiocese::query()
            ->where(function ($dioceseQuery) use ($pattern) {
                CaseInsensitiveSearch::applyColumnLike($dioceseQuery, 'name', $pattern);
                CaseInsensitiveSearch::applyColumnLike($dioceseQuery, 'code', $pattern, 'or');
            })
            ->pluck('id');
    }

    /**
     * Scope by diocese
     */
    public function scopeByDiocese($query, ?string $dioceseId, bool $currentOnly = true)
    {
        if (!$dioceseId) {
            return $query;
        }

        $table = $this->getTable();

        return $query->where(function ($q) use ($dioceseId, $currentOnly, $table) {
            $q->where(function ($legacy) use ($dioceseId, $currentOnly, $table) {
                $legacy->where("{$table}.archdiocese_id", $dioceseId);

                if ($currentOnly) {
                    $legacy->where("{$table}.is_current", true);
                }
            })->orWhereIn("{$table}.id", BishopAppointment::query()
                ->select('bishop_id')
                ->where('diocese_id', $dioceseId)
                ->when($currentOnly, fn ($appointment) => $appointment->where('is_current', true))
                ->whereNull('deleted_at'));
        });
    }

    /**
     * Scope by title
     */
    public function scopeByTitle($query, ?string $titleId)
    {
        if (!$titleId) {
            return $query;
        }

        return $query->where('ecclesiastical_title_id', $titleId);
    }
}

