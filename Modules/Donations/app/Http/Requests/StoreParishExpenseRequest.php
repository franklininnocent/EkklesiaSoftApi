<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParishExpenseRequest extends FormRequest
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
            'category' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'expense_date' => ['required', 'date'],
            'payee' => ['nullable', 'string', 'max:180'],
            'method' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
        ];
    }
}
