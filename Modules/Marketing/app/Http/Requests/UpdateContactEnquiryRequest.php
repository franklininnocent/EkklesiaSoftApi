<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Marketing\Models\ContactEnquiry;

class UpdateContactEnquiryRequest extends FormRequest
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
            'enquiry_status' => ['sometimes', 'string', 'in:'.implode(',', ContactEnquiry::enquiryStatuses())],
            'is_read' => ['sometimes', 'boolean'],
        ];
    }
}
