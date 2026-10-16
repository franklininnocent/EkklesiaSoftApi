<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\Tenants\Database\Factories\ChurchProfileFactory;

/**
 * ChurchProfile Model
 * 
 * Extended church information including denominational affiliation,
 * ecclesiastical hierarchy, and church identity.
 * One-to-one relationship with Tenant.
 */
class ChurchProfile extends Model
{
    use HasFactory;

    protected $table = 'church_profiles';

    protected $fillable = [
        'tenant_id',
        'denomination_id',
        'archdiocese_id',
        'bishop_id',
        'founded_year',
        'country',
        'phone',
        'email',
        'website',
        'about',
        'vision',
        'mission',
        'core_values',
        'service_times',
        'patron_name',
        'patron_image_path',
    ];

    protected $casts = [
        'founded_year' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant/church this profile belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the denomination
     */
    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }

    /**
     * Get the archdiocese
     */
    public function archdiocese(): BelongsTo
    {
        return $this->belongsTo(Archdiocese::class);
    }

    /**
     * Get the presiding bishop
     */
    public function bishop(): BelongsTo
    {
        return $this->belongsTo(Bishop::class);
    }

    /**
     * Resolve the current diocesan ordinary from canonical appointment history.
     */
    public function resolvePresidingBishop(): ?BishopManagement
    {
        if (! $this->archdiocese_id) {
            return null;
        }

        $leadership = app(DioceseLeadershipQueryService::class)
            ->getCurrentLeadership((int) $this->archdiocese_id);

        $bishopId = $leadership['ordinary']['bishop_id'] ?? null;

        if (! $bishopId) {
            return null;
        }

        return BishopManagement::query()
            ->with('ecclesiasticalTitle')
            ->find($bishopId);
    }

    protected static function newFactory(): ChurchProfileFactory
    {
        return ChurchProfileFactory::new();
    }
}
