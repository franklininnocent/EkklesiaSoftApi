<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class SetFeatureDependenciesRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requires' => ['present', 'array', 'max:20'],
            'requires.*' => ['string', self::CODE_REGEX, 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $requires = $this->input('requires');
        if (is_array($requires)) {
            $this->merge(['requires' => array_map(static fn ($c) => is_string($c) ? strtoupper(trim($c)) : $c, $requires)]);
        }
    }
}
