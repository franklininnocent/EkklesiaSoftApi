<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Marketing\Models\ContactEnquiry;

class ListContactEnquiriesRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'enquiry_status' => ['nullable', 'string', 'in:'.implode(',', ContactEnquiry::enquiryStatuses())],
            'delivery_status' => ['nullable', 'string', 'in:'.implode(',', ContactEnquiry::deliveryStatuses())],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date', 'after_or_equal:submitted_from'],
            'sort' => ['nullable', 'string', 'in:created_at,full_name,email,enquiry_status,delivery_status,reference_number'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
