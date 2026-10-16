<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fund_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:180'],
            'code' => ['required', 'string', 'max:60'],
            'assignment_mode' => ['required', 'in:uniform,individual,uniform_with_exceptions'],
            'description' => ['nullable', 'string'],
            'target_amount' => ['nullable', 'numeric', 'min:0'],
            'default_family_target' => ['required', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'installment_count' => ['nullable', 'integer', 'min:1', 'max:120'],
            'installment_frequency' => ['nullable', 'in:weekly,monthly,quarterly,half_yearly,yearly,custom'],
            'installment_interval_days' => ['nullable', 'integer', 'min:1', 'required_if:installment_frequency,custom'],
            'auto_generate_installments' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active,completed,cancelled'],
            'is_tax_deductible' => ['nullable', 'boolean'],
            'assignments' => ['nullable', 'array'],
            'assignments.*.family_id' => ['required_with:assignments', 'uuid'],
            'assignments.*.target_amount' => ['nullable', 'numeric', 'min:0'],
            'assignments.*.effective_from' => ['nullable', 'date'],
            'assignments.*.effective_to' => ['nullable', 'date'],
            'assignments.*.is_exempt' => ['nullable', 'boolean'],
            'assignments.*.status' => ['nullable', 'in:active,inactive'],
            'assignments.*.notes' => ['nullable', 'string'],
        ];
    }
}
