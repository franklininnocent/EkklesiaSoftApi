<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Authentication\Models\User;

/**
 * Base for platform subscription admin requests. Route middleware
 * (subscriptions.platform.permission) performs RBAC; this validates input only.
 */
abstract class SubscriptionAdminRequest extends FormRequest
{
    public const MONEY_REGEX = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    public const CODE_REGEX = 'regex:/^[A-Z][A-Z0-9_]{1,63}$/';

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    protected function reasonRules(bool $required = false): array
    {
        return ['reason' => [$required ? 'required' : 'nullable', 'string', 'min:3', 'max:500']];
    }
}
