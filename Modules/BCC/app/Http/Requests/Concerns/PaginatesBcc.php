<?php

namespace Modules\BCC\Http\Requests\Concerns;

trait PaginatesBcc
{
    /**
     * @return array<string, mixed>
     */
    protected function paginationRules(int $max = 100): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$max],
        ];
    }
}
