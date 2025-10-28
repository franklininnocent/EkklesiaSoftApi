<?php

namespace Modules\EcclesiasticalData\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Models\EcclesiasticalAuditLog;

trait HasAuditTrail
{
    /**
     * Boot the audit trail trait
     */
    protected static function bootHasAuditTrail(): void
    {
        static::created(function ($model) {
            $model->auditAction('create', null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            if (!empty($changes) && !isset($changes['updated_at'])) {
                $model->auditAction('update', $model->getOriginal(), $changes);
            }
        });

        static::deleted(function ($model) {
            $action = $model->isForceDeleting() ? 'force_delete' : 'delete';
            $model->auditAction($action, $model->getOriginal(), null);
        });
    }

    /**
     * Log an audit action
     */
    protected function auditAction(string $action, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::user();
        
        EcclesiasticalAuditLog::create([
            'id' => Str::uuid(),
            'entity_type' => $this->getTable(),
            'entity_id' => $this->id ?? Str::uuid(),
            'action' => $action,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'changes' => $this->calculateChanges($oldValues, $newValues),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Calculate specific changes between old and new values
     */
    protected function calculateChanges(?array $oldValues, ?array $newValues): ?string
    {
        if (!$oldValues || !$newValues) {
            return null;
        }

        $changes = [];
        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            if ($oldValue !== $newValue && $key !== 'updated_at') {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return !empty($changes) ? json_encode($changes) : null;
    }

    /**
     * Get audit history for this model
     */
    public function auditHistory()
    {
        return EcclesiasticalAuditLog::where('entity_type', $this->getTable())
            ->where('entity_id', $this->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

