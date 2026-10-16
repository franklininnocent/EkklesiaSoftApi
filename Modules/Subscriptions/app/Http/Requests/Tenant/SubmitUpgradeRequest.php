<?php

namespace Modules\Subscriptions\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\PlanVersion;

/**
 * A church asks Ekklesia for a plan change. The church comes from the signed-in context;
 * any tenant_id in the body is ignored.
 */
class SubmitUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required_without:plan_code', 'nullable', 'integer', 'min:1'],
            'plan_code' => ['required_without:plan_id', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,63}$/'],
            'billing_interval' => ['nullable', Rule::in(PlanVersion::INTERVALS)],
            'feature_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,63}$/'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('billing_interval'))) {
            $this->merge(['billing_interval' => strtoupper($this->input('billing_interval'))]);
        }
    }
}
