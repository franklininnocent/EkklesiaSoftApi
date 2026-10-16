<?php

namespace Modules\ApplicationAccess\Http\Requests\Concerns;

trait InteractsWithApplicationAccessPagination
{
    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(int $maxPerPage = 100): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolvedPage(array $validated): int
    {
        return max(1, (int) ($validated['page'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function resolvedPerPage(array $validated, int $default = 25, int $max = 100): int
    {
        return min($max, max(1, (int) ($validated['per_page'] ?? $default)));
    }
}
