<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RemoveBccMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exit_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'exit_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
