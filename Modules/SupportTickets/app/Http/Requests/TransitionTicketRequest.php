<?php

namespace Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SupportTickets\Support\TicketStatus;

class TransitionTicketRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(TicketStatus::all())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
