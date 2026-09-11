<?php

namespace Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Support\TicketPriority;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_type_id' => [
                'required',
                'integer',
                Rule::exists('support_request_types', 'id')->where('active', true),
            ],
            'category_id' => ['nullable', 'integer', 'exists:support_categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:support_categories,id'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'priority' => ['nullable', 'string', Rule::in(TicketPriority::all())],
            'business_impact' => ['nullable', 'string', 'max:64'],
            'affected_module' => ['nullable', 'string', 'max:128'],
            'occurrence_at' => ['nullable', 'date'],
            'steps_to_reproduce' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'actual_result' => ['nullable', 'string', 'max:5000'],
            'error_message' => ['nullable', 'string', 'max:5000'],
            'bug_details' => ['nullable', 'array'],
            'bug_details.frequency' => ['nullable', 'string', Rule::in(['always', 'often', 'sometimes', 'once'])],
            'bug_details.browser' => ['nullable', 'string', 'max:128'],
            'bug_details.device' => ['nullable', 'string', 'max:128'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeId = $this->input('request_type_id');
            $categoryId = $this->input('category_id');

            if (! $typeId || ! $categoryId) {
                return;
            }

            $valid = SupportCategory::query()
                ->whereKey($categoryId)
                ->where('request_type_id', $typeId)
                ->where('active', true)
                ->exists();

            if (! $valid) {
                $validator->errors()->add('category_id', 'Invalid category for this request type.');
            }
        });
    }
}
