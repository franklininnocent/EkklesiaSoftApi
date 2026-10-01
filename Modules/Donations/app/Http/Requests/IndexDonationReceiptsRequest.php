<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDonationReceiptsRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'receipt_number',
        'issued_on',
        'family_name',
        'payer_name',
        'method',
        'amount',
    ];

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
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'family_id' => ['sometimes', 'nullable', 'uuid'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'is_void' => ['sometimes', 'nullable'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function sortColumn(): string
    {
        $sort = $this->string('sort')->toString();

        return $sort !== '' ? $sort : 'issued_on';
    }

    public function sortDirection(): string
    {
        return $this->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
    }
}
