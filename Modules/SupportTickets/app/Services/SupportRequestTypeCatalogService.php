<?php

namespace Modules\SupportTickets\Services;

use Illuminate\Support\Str;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;

class SupportRequestTypeCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function serializeType(SupportRequestType $type): array
    {
        return [
            'id' => $type->id,
            'slug' => $type->slug,
            'name' => $type->name,
            'sort_order' => $type->sort_order,
            'active' => $type->active,
            'requires_bug_fields' => $type->requires_bug_fields,
            'categories' => $type->catalogCategories->map(fn (SupportCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
                'active' => $category->active,
            ])->values()->all(),
            'tickets_count' => $type->tickets_count ?? $type->tickets()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createType(array $payload): SupportRequestType
    {
        $slug = $this->uniqueSlug((string) $payload['name']);

        return SupportRequestType::query()->create([
            'slug' => $slug,
            'name' => trim((string) $payload['name']),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'active' => (bool) ($payload['active'] ?? true),
            'requires_bug_fields' => (bool) ($payload['requires_bug_fields'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateType(SupportRequestType $type, array $payload): SupportRequestType
    {
        $type->fill([
            'name' => trim((string) $payload['name']),
            'sort_order' => (int) ($payload['sort_order'] ?? $type->sort_order),
            'active' => (bool) ($payload['active'] ?? $type->active),
            'requires_bug_fields' => (bool) ($payload['requires_bug_fields'] ?? $type->requires_bug_fields),
        ]);
        $type->save();

        return $type->fresh(['catalogCategories']);
    }

    public function deleteType(SupportRequestType $type): void
    {
        if ($this->typeHasTickets($type)) {
            throw new \RuntimeException('Cannot delete a request type that is used by support tickets. Deactivate it instead.', 409);
        }

        $type->catalogCategories()->delete();
        $type->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCategory(SupportRequestType $type, array $payload): SupportCategory
    {
        return SupportCategory::query()->create([
            'request_type_id' => $type->id,
            'parent_id' => null,
            'name' => trim((string) $payload['name']),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'active' => (bool) ($payload['active'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCategory(SupportCategory $category, array $payload): SupportCategory
    {
        $category->fill([
            'name' => trim((string) $payload['name']),
            'sort_order' => (int) ($payload['sort_order'] ?? $category->sort_order),
            'active' => (bool) ($payload['active'] ?? $category->active),
        ]);
        $category->save();

        return $category->fresh();
    }

    public function deleteCategory(SupportCategory $category): void
    {
        if ($this->categoryHasTickets($category)) {
            throw new \RuntimeException('Cannot delete a category that is used by support tickets. Deactivate it instead.', 409);
        }

        $category->delete();
    }

    public function typeHasTickets(SupportRequestType $type): bool
    {
        return SupportTicket::query()->where('request_type_id', $type->id)->exists();
    }

    public function categoryHasTickets(SupportCategory $category): bool
    {
        return SupportTicket::query()
            ->where(function ($query) use ($category): void {
                $query->where('category_id', $category->id)
                    ->orWhere('subcategory_id', $category->id);
            })
            ->exists();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'request-type';
        }

        $slug = $base;
        $suffix = 2;

        while (SupportRequestType::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
