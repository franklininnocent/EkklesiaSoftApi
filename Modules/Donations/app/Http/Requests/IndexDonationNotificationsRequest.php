<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDonationNotificationsRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'notification_type',
        'channel',
        'recipient',
        'status',
        'created_at',
        'sent_at',
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['queued', 'sent', 'failed'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function sortColumn(): string
    {
        $sort = $this->string('sort')->toString();

        return $sort !== '' ? $sort : 'created_at';
    }

    public function sortDirection(): string
    {
        return $this->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
    }
}
