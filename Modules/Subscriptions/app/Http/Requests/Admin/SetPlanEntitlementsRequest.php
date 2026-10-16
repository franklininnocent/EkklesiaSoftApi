<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class SetPlanEntitlementsRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entitlements' => ['present', 'array', 'max:500'],
            'entitlements.*.feature_code' => ['required', 'string', self::CODE_REGEX, 'distinct'],
            'entitlements.*.is_enabled' => ['sometimes', 'boolean'],
            'entitlements.*.numeric_value' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'entitlements.*.tier_value' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('entitlements');
        if (is_array($rows)) {
            $this->merge(['entitlements' => array_map(static function ($row) {
                if (is_array($row) && isset($row['feature_code']) && is_string($row['feature_code'])) {
                    $row['feature_code'] = strtoupper(trim($row['feature_code']));
                }

                return $row;
            }, $rows)]);
        }
    }
}
