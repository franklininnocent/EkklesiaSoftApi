<?php

namespace Modules\Sacraments\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Models\TenantSacramentSetting;
use Modules\Sacraments\Support\SacramentTypeCode;

class TenantSacramentSettingsService
{
    public function __construct(
        protected SacramentAuditService $audit,
        protected SacramentDefinitionRegistry $definitions
    ) {}

    public function ensureDefaults(int $tenantId, ?int $userId = null): void
    {
        $typeIds = SacramentType::query()
            ->where('active', true)
            ->pluck('id')
            ->all();

        if ($typeIds === []) {
            return;
        }

        $existing = TenantSacramentSetting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('sacrament_type_id', $typeIds)
            ->pluck('sacrament_type_id')
            ->all();
        $existingSet = array_flip($existing);

        $now = now();
        $rows = [];
        foreach ($typeIds as $typeId) {
            if (isset($existingSet[$typeId])) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId,
                'sacrament_type_id' => $typeId,
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            TenantSacramentSetting::query()->insertOrIgnore($rows);
        }
    }

    /**
     * @return array<int, bool> sacrament_type_id => is_active
     */
    public function enabledMap(int $tenantId): array
    {
        $map = [];
        $rows = TenantSacramentSetting::query()
            ->where('tenant_id', $tenantId)
            ->get(['sacrament_type_id', 'is_active']);

        foreach ($rows as $row) {
            $map[(int) $row->sacrament_type_id] = (bool) $row->is_active;
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    public function disabledTypeIdsForTenant(int $tenantId): array
    {
        $ids = [];
        foreach ($this->enabledMap($tenantId) as $typeId => $enabled) {
            if (! $enabled) {
                $ids[] = (int) $typeId;
            }
        }

        return $ids;
    }

    public function isEnabledForTenant(int $tenantId, int $sacramentTypeId): bool
    {
        $map = $this->enabledMap($tenantId);

        if (array_key_exists((int) $sacramentTypeId, $map)) {
            return $map[(int) $sacramentTypeId];
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $userId = null): array
    {
        $this->ensureDefaults($tenantId, $userId);

        $settings = TenantSacramentSetting::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('sacrament_type_id');

        $types = SacramentType::query()
            ->where('active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($types as $type) {
            $setting = $settings->get($type->id);
            $rows[] = $this->present($type, $setting);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAvailability(int $tenantId, int $sacramentTypeId, bool $isActive, ?int $userId = null): array
    {
        $type = SacramentType::query()
            ->where('id', $sacramentTypeId)
            ->where('active', true)
            ->first();

        if (! $type) {
            throw new SacramentBusinessRuleException(
                'sacrament_type_unavailable',
                'Sacrament type was not found or is not available.',
                ['sacrament_type_id' => $sacramentTypeId],
                422
            );
        }

        return DB::transaction(function () use ($tenantId, $type, $isActive, $userId) {
            $setting = TenantSacramentSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('sacrament_type_id', $type->id)
                ->lockForUpdate()
                ->first();

            $previous = $setting ? (bool) $setting->is_active : true;

            if (! $setting) {
                $setting = new TenantSacramentSetting([
                    'tenant_id' => $tenantId,
                    'sacrament_type_id' => $type->id,
                    'is_active' => $previous,
                    'created_by' => $userId,
                ]);
            }

            if ($previous === $isActive && $setting->exists) {
                return $this->present($type, $setting);
            }

            $setting->is_active = $isActive;
            $setting->updated_by = $userId;
            $setting->save();

            $this->audit->log(
                $tenantId,
                $isActive ? 'sacrament_setting.activated' : 'sacrament_setting.deactivated',
                (string) $setting->id,
                ['is_active' => $previous],
                ['is_active' => $isActive],
                [
                    'sacrament_type_id' => $type->id,
                    'sacrament_code' => $type->code,
                    'sacrament_name' => $type->name,
                    'changed_by' => $userId ?? Auth::id(),
                ],
                'tenant_sacrament_setting'
            );

            return $this->present($type, $setting);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SacramentType $type, ?TenantSacramentSetting $setting): array
    {
        $canonical = SacramentTypeCode::normalize($type->code);
        $definition = $canonical ? $this->definitions->forTypeCode($canonical) : null;

        return [
            'id' => $setting?->id,
            'sacrament_type_id' => $type->id,
            'code' => $type->code,
            'canonical_code' => $canonical,
            'name' => $definition['display_name'] ?? $type->name,
            'description' => $type->description,
            'category' => $definition['category'] ?? $type->category,
            'display_order' => $type->display_order,
            'is_active' => $setting ? (bool) $setting->is_active : true,
            'updated_at' => optional($setting?->updated_at)?->toIso8601String(),
        ];
    }
}
