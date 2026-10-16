<?php

namespace Modules\Family\app\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

trait ValidatesPersonParentFields
{
    /**
     * @return array<string, mixed>
     */
    protected function personParentFieldRules(string $prefix = ''): array
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        $dot = $prefix !== '' ? $prefix.'.' : '';

        $personExists = Rule::exists('persons', 'id')->where(function ($query) use ($tenantId) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId)->whereNull('deleted_at');
            }
        });

        return [
            "{$dot}father_person_id" => ['nullable', 'uuid', $personExists],
            "{$dot}father_name" => ['nullable', 'string', 'max:255'],
            "{$dot}mother_person_id" => ['nullable', 'uuid', $personExists],
            "{$dot}mother_name" => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function mergeEmptyParentIdsToNull(): void
    {
        $payload = $this->all();
        $changed = false;

        foreach (['father_person_id', 'mother_person_id'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] === '') {
                $payload[$key] = null;
                $changed = true;
            }
        }

        if (isset($payload['members']) && is_array($payload['members'])) {
            foreach ($payload['members'] as $index => $member) {
                if (! is_array($member)) {
                    continue;
                }
                foreach (['father_person_id', 'mother_person_id'] as $key) {
                    if (array_key_exists($key, $member) && $member[$key] === '') {
                        $payload['members'][$index][$key] = null;
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $this->merge($payload);
        }
    }
}
