<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Services\BccLeadershipService;

class TerminateBccLeadershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date', 'before_or_equal:today'],
            'exit_reason' => ['required', 'string', Rule::in(BccLeadershipService::EXIT_REASONS)],
            'remarks' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'effective_to' => $this->input(
                'effective_to',
                $this->input('term_end_date', now()->toDateString())
            ),
            'exit_reason' => $this->input('exit_reason', 'removed'),
            'remarks' => $this->input('remarks', $this->input('notes')),
        ]);
    }
}
