<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class TerminateLeadershipTermRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date'],
            'exit_reason' => ['required', 'string', 'max:30', Rule::in(self::LEADERSHIP_EXIT_REASONS)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
