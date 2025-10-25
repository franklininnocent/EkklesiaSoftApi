<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;

/**
 * TenantStatusAudit Model
 * 
 * Represents an audit log entry for tenant status changes.
 * This model is read-only after creation - audit logs should never be updated or deleted.
 * 
 * @property int $id
 * @property int $tenant_id
 * @property int $previous_status
 * @property int $new_status
 * @property string $action
 * @property string|null $reason
 * @property int $performed_by
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * 
 * @property-read Tenant $tenant
 * @property-read User $user
 */
class TenantStatusAudit extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'tenant_status_audit';
    
    /**
     * Indicates if the model should be timestamped.
     * We only use created_at, no updated_at (audit logs don't update)
     */
    public const UPDATED_AT = null;
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'previous_status',
        'new_status',
        'action',
        'reason',
        'performed_by',
        'ip_address',
        'user_agent',
        'metadata',
    ];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'tenant_id' => 'integer',
        'previous_status' => 'integer',
        'new_status' => 'integer',
        'performed_by' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
    
    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'ip_address',
        'user_agent',
        'metadata',
    ];
    
    /**
     * Action constants
     */
    const ACTION_ACTIVATED = 'activated';
    const ACTION_DEACTIVATED = 'deactivated';
    
    /**
     * Status constants
     */
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;
    
    /**
     * Get the tenant that this audit log belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
    
    /**
     * Get the user who performed the status change.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
    
    /**
     * Maximum number of audit records to keep per tenant
     */
    const MAX_RECORDS_PER_TENANT = 25;
    
    /**
     * Boot method - prevent updates and auto-cleanup old records.
     */
    protected static function boot()
    {
        parent::boot();
        
        // Prevent updates to audit records (immutability for existing records)
        static::updating(function ($model) {
            throw new \Exception('Audit records cannot be updated.');
        });
        
        // Auto-cleanup after creating a new record
        static::created(function ($model) {
            $model->cleanupOldRecords();
        });
    }
    
    /**
     * Clean up old audit records, keeping only the last 25 per tenant.
     * Uses efficient subquery with LIMIT and OFFSET.
     */
    protected function cleanupOldRecords(): void
    {
        try {
            // Delete all records older than the 25th most recent record for this tenant
            DB::statement("
                DELETE FROM tenant_status_audit
                WHERE tenant_id = ?
                AND id NOT IN (
                    SELECT id FROM tenant_status_audit
                    WHERE tenant_id = ?
                    ORDER BY created_at DESC, id DESC
                    LIMIT ?
                )
            ", [$this->tenant_id, $this->tenant_id, self::MAX_RECORDS_PER_TENANT]);
            
            Log::debug('Audit cleanup executed', [
                'tenant_id' => $this->tenant_id,
                'max_records' => self::MAX_RECORDS_PER_TENANT,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the audit creation
            Log::error('Audit cleanup failed', [
                'tenant_id' => $this->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    /**
     * Scope to filter by action.
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }
    
    /**
     * Scope to filter by performed by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('performed_by', $userId);
    }
    
    /**
     * Scope to get recent audit logs.
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
    
    /**
     * Scope to get audit logs within a date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
    
    /**
     * Static method to manually cleanup all tenants' old records.
     * Useful for one-time cleanup or scheduled maintenance.
     * 
     * @param int|null $limit Number of records to keep per tenant (default: MAX_RECORDS_PER_TENANT)
     * @return array Cleanup statistics
     */
    public static function cleanupAllTenants(?int $limit = null): array
    {
        $limit = $limit ?? self::MAX_RECORDS_PER_TENANT;
        $stats = [
            'tenants_processed' => 0,
            'records_deleted' => 0,
            'start_time' => now(),
        ];
        
        try {
            // Get all tenants with audit records
            $tenants = DB::table('tenant_status_audit')
                ->select('tenant_id')
                ->groupBy('tenant_id')
                ->get();
            
            foreach ($tenants as $tenant) {
                // Count records before cleanup
                $countBefore = self::where('tenant_id', $tenant->tenant_id)->count();
                
                // Delete old records for this tenant
                DB::statement("
                    DELETE FROM tenant_status_audit
                    WHERE tenant_id = ?
                    AND id NOT IN (
                        SELECT id FROM tenant_status_audit
                        WHERE tenant_id = ?
                        ORDER BY created_at DESC, id DESC
                        LIMIT ?
                    )
                ", [$tenant->tenant_id, $tenant->tenant_id, $limit]);
                
                // Count records after cleanup
                $countAfter = self::where('tenant_id', $tenant->tenant_id)->count();
                $deleted = $countBefore - $countAfter;
                
                $stats['tenants_processed']++;
                $stats['records_deleted'] += $deleted;
                
                if ($deleted > 0) {
                    Log::info('Manual cleanup executed', [
                        'tenant_id' => $tenant->tenant_id,
                        'records_before' => $countBefore,
                        'records_after' => $countAfter,
                        'records_deleted' => $deleted,
                    ]);
                }
            }
            
            $stats['end_time'] = now();
            $stats['duration_seconds'] = $stats['start_time']->diffInSeconds($stats['end_time']);
            $stats['success'] = true;
            
        } catch (\Exception $e) {
            $stats['success'] = false;
            $stats['error'] = $e->getMessage();
            
            Log::error('Manual cleanup failed', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);
        }
        
        return $stats;
    }
    
    /**
     * Get count of audit records per tenant.
     * Useful for monitoring retention policy.
     * 
     * @return \Illuminate\Support\Collection
     */
    public static function getRecordCountsByTenant(): \Illuminate\Support\Collection
    {
        return DB::table('tenant_status_audit')
            ->select('tenant_id', DB::raw('COUNT(*) as record_count'))
            ->groupBy('tenant_id')
            ->orderBy('record_count', 'desc')
            ->get();
    }
    
    /**
     * Get a human-readable action description.
     */
    public function getActionDescriptionAttribute(): string
    {
        $tenantName = $this->tenant->name ?? 'Unknown Tenant';
        $userName = $this->user->name ?? 'Unknown User';
        
        return match($this->action) {
            self::ACTION_ACTIVATED => "{$userName} activated {$tenantName}",
            self::ACTION_DEACTIVATED => "{$userName} deactivated {$tenantName}",
            default => "{$userName} changed status of {$tenantName}",
        };
    }
    
    /**
     * Get a formatted timestamp.
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('M d, Y h:i A');
    }
    
    /**
     * Static method to create an audit log entry.
     * 
     * @param Tenant $tenant
     * @param int $previousStatus
     * @param int $newStatus
     * @param string|null $reason
     * @param int $performedBy
     * @param array $additionalData
     * @return self
     */
    public static function log(
        Tenant $tenant,
        int $previousStatus,
        int $newStatus,
        ?string $reason,
        int $performedBy,
        array $additionalData = []
    ): self {
        // Determine action
        $action = $newStatus === self::STATUS_ACTIVE 
            ? self::ACTION_ACTIVATED 
            : self::ACTION_DEACTIVATED;
        
        // Create audit log
        return self::create([
            'tenant_id' => $tenant->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'action' => $action,
            'reason' => $reason,
            'performed_by' => $performedBy,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => array_merge([
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
            ], $additionalData),
        ]);
    }
}

