<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class MigrateVersionTenantsRequest extends SubscriptionAdminRequest
{
    public const MAX_BATCH = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_version_id' => ['nullable', 'integer', 'min:1'],
            'tenant_ids' => ['nullable', 'array', 'max:'.self::MAX_BATCH],
            'tenant_ids.*' => ['integer', 'min:1', 'distinct'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BATCH],
            'keep_contracted_price' => ['sometimes', 'boolean'],
            'confirm_impact' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
            ...$this->reasonRules(true),
        ];
    }
}
