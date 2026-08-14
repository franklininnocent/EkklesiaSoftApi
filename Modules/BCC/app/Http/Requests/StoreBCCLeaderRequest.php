<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Services\BccLeadershipService;

class StoreBCCLeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_member_id' => ['required', 'uuid', 'exists:family_members,id'],
            'role' => ['required', 'string', Rule::in(BccLeadershipService::ROLES)],
            'role_description' => ['nullable', 'string', 'max:255'],
            'appointed_date' => ['nullable', 'date', 'before_or_equal:today'],
            'term_start_date' => ['nullable', 'date'],
            'term_end_date' => ['nullable', 'date', 'after_or_equal:term_start_date'],
            'leader_phone' => ['nullable', 'string', 'max:20'],
            'leader_email' => ['nullable', 'email', 'max:255'],
            'responsibilities' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
