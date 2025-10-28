<?php

namespace Modules\Sacraments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenants\Models\Tenant;

/**
 * Sacrament Model
 * 
 * Represents individual sacrament administration records
 */
class Sacrament extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Sacraments\Database\Factories\SacramentFactory::new();
    }

    protected $table = 'sacraments';

    protected $fillable = [
        'tenant_id',
        'sacrament_type_id',
        'recipient_name',
        'date_administered',
        'place_administered',
        'minister_name',
        'minister_title',
        'certificate_number',
        'book_number',
        'page_number',
        'recipient_birth_date',
        'recipient_birth_place',
        'father_name',
        'mother_name',
        'godparent1_name',
        'godparent2_name',
        'witnesses',
        'notes',
        'document_path',
        'status',
        'conditional_date',
        'conditional_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_administered' => 'date',
        'recipient_birth_date' => 'date',
        'conditional_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Get the tenant (church) this sacrament belongs to
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the sacrament type
     */
    public function sacramentType(): BelongsTo
    {
        return $this->belongsTo(SacramentType::class, 'sacrament_type_id');
    }

    /**
     * Get the user who created this record
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this record
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Filter by tenant
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope: Filter by sacrament type
     */
    public function scopeBySacramentType($query, int $typeId)
    {
        return $query->where('sacrament_type_id', $typeId);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Search by recipient name
     */
    public function scopeSearchRecipient($query, string $search)
    {
        return $query->where('recipient_name', 'ILIKE', "%{$search}%");
    }

    /**
     * Scope: Filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_administered', [$startDate, $endDate]);
    }

    /**
     * Check if sacrament is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get full certificate reference
     */
    public function getCertificateReferenceAttribute(): string
    {
        $parts = array_filter([
            $this->book_number ? "Book: {$this->book_number}" : null,
            $this->page_number ? "Page: {$this->page_number}" : null,
            $this->certificate_number ? "Cert: {$this->certificate_number}" : null,
        ]);
        
        return !empty($parts) ? implode(' | ', $parts) : 'No reference';
    }
}
