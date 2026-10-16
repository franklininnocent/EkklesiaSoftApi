<?php

namespace Modules\PastoralCare\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPastoralCareRequest extends FormRequest
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
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
