<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\DefaultSeeds\MassIntentionCategoriesDefaultSeedDefinition;
use Modules\MassIntentions\Models\MassIntentionCategory;

class MassIntentionCategoryService
{
    public function __construct(
        private readonly MassIntentionAuditService $audits,
        private readonly MassIntentionCategoriesDefaultSeedDefinition $defaultSeed,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(int $tenantId, ?int $userId = null): array
    {
        $this->ensureDefaultsForTenant($tenantId, $userId);

        return MassIntentionCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MassIntentionCategory $row) => $this->toArray($row))
            ->values()
            ->all();
    }

    public function ensureDefaultsForTenant(int $tenantId, ?int $userId): void
    {
        $hasAny = MassIntentionCategory::query()->where('tenant_id', $tenantId)->exists();
        if ($hasAny) {
            return;
        }

        $this->defaultSeed->execute($tenantId, $userId);
    }

    public function findForTenant(int $tenantId, string $id): MassIntentionCategory
    {
        $category = MassIntentionCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('active', true)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'mass_intention_category_id' => 'Select a valid Mass intention type.',
            ]);
        }

        return $category;
    }

    public function create(int $tenantId, User $actor, string $name): MassIntentionCategory
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter an intention type.',
            ]);
        }

        if (mb_strlen($name) > 128) {
            throw ValidationException::withMessages([
                'name' => 'Intention type is too long.',
            ]);
        }

        $duplicate = MassIntentionCategory::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'This intention type already exists.',
            ]);
        }

        $maxOrder = (int) MassIntentionCategory::query()
            ->where('tenant_id', $tenantId)
            ->max('sort_order');

        $category = MassIntentionCategory::query()->create([
            'tenant_id' => $tenantId,
            'code' => $this->uniqueCode($tenantId, $name),
            'name' => $name,
            'active' => true,
            'sort_order' => $maxOrder + 1,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audits->record($tenantId, 'category.created', $actor, null, null, [
            'category_id' => $category->id,
            'name' => $category->name,
        ]);

        return $category;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(MassIntentionCategory $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'active' => $category->active,
            'sort_order' => $category->sort_order,
        ];
    }

    private function uniqueCode(int $tenantId, string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        if ($base === '') {
            $base = 'CUSTOM';
        }
        $base = Str::limit($base, 64, '');
        $code = $base;
        $suffix = 2;
        while (MassIntentionCategory::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            $code = Str::limit($base, 60, '').'_'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
