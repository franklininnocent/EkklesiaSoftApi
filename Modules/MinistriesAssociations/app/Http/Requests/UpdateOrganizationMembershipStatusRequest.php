<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class UpdateOrganizationMembershipStatusRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                OrganizationMembership::STATUS_ACTIVE,
                OrganizationMembership::STATUS_INACTIVE,
                OrganizationMembership::STATUS_SUSPENDED,
                OrganizationMembership::STATUS_RESIGNED,
                OrganizationMembership::STATUS_EXITED,
                OrganizationMembership::STATUS_DECEASED,
            ])],
            'exit_date' => [
                Rule::requiredIf(fn () => in_array(
                    $this->input('status'),
                    self::MEMBERSHIP_EXIT_STATUSES,
                    true
                )),
                'nullable',
                'date',
            ],
            'exit_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
