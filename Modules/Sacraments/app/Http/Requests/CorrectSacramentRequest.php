<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\CanonicalDelegationStatus;
use Modules\Sacraments\Support\EcclesialAffiliation;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\SacramentDispensationType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentStatus;

class CorrectSacramentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => 'required|integer|min:0',
            'reason' => 'required|string|min:3|max:2000',
            'date_administered' => 'nullable|date',
            'place_administered' => 'nullable|string|max:255',
            'status' => ['nullable', SacramentStatus::validationRule()],
            'book_number' => 'nullable|string|max:255',
            'page_number' => 'nullable|string|max:255',
            'registry_entry' => 'nullable|string|max:100',
            'certificate_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'participants' => 'nullable|array|min:1',
            'participants.*.role' => ['required_with:participants', SacramentParticipantRole::rule()],
            'participants.*.source' => ['required_with:participants', SacramentParticipantSource::rule()],
            'participants.*.sort_order' => 'nullable|integer|min:0',
            'participants.*.family_member_id' => 'nullable|uuid',
            'participants.*.church_leadership_id' => 'nullable|integer',
            'participants.*.affiliation_type' => 'nullable|in:home_parish,other',
            'participants.*.affiliation_parish_name' => 'nullable|string|max:255',
            'participants.*.affiliation_parish_address' => 'nullable|string',
            'participants.*.affiliation_diocese_name' => 'nullable|string|max:255',
            'participants.*.affiliation_diocese_region' => 'nullable|string|max:255',
            'participants.*.affiliation_diocese_country' => 'nullable|string|max:255',
            'participants.*.external_full_name' => 'nullable|string|max:255',
            'participants.*.external_date_of_birth' => 'nullable|date',
            'participants.*.external_gender' => 'nullable|in:male,female,other',
            'participants.*.external_address' => 'nullable|string',
            'participants.*.external_contact_number' => 'nullable|string|max:20',
            'participants.*.external_title' => 'nullable|string|max:50',
            'participants.*.external_minister_role' => 'nullable|string|max:80',
            'participants.*.baptismal_status' => ['nullable', BaptismalStatus::rule()],
            'participants.*.ecclesial_affiliation_code' => ['nullable', EcclesialAffiliation::rule()],
            'participants.*.ecclesial_affiliation_label' => 'nullable|string|max:255',
            'participants.*.canonical_delegation_status' => ['nullable', CanonicalDelegationStatus::rule()],
            'participants.*.father_name' => 'nullable|string|max:255',
            'participants.*.mother_name' => 'nullable|string|max:255',
            'marriage_canonical_classification' => ['nullable', MarriageCanonicalClassification::rule()],
            'dispensations' => 'nullable|array',
            'dispensations.*.dispensation_type' => ['required_with:dispensations', SacramentDispensationType::rule()],
            'dispensations.*.granting_authority' => 'nullable|string|max:255',
            'dispensations.*.protocol_number' => 'nullable|string|max:80',
            'dispensations.*.date_granted' => 'nullable|date',
            'participants.*.snapshot_json' => 'prohibited',
        ];
    }
}
