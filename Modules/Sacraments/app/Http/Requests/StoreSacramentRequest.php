<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\CanonicalDelegationStatus;
use Modules\Sacraments\Support\EcclesialAffiliation;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\SacramentDispensationType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentPlaceClassification;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Support\TenantContext;

class StoreSacramentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach ($this->nullableFields() as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (isset($data['status'])) {
            $data['status'] = SacramentStatus::normalize($data['status']) ?? $data['status'];
        }

        if (empty($data['recipient_birth_date']) && ! empty($data['recipient_dob'])) {
            $data['recipient_birth_date'] = $data['recipient_dob'];
        }

        if (! isset($data['status']) || $data['status'] === null) {
            $data['status'] = SacramentStatus::REGISTERED;
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $hasParticipants = is_array($this->input('participants')) && count($this->input('participants')) > 0;

        $rules = [
            'sacrament_type_id' => 'required|exists:sacrament_types,id',
            'family_id' => [
                'nullable',
                'uuid',
                Rule::exists('families', 'id')->where('tenant_id', $tenantId),
            ],
            'person_id' => [
                'nullable',
                'uuid',
                Rule::exists('persons', 'id')->where('tenant_id', $tenantId),
            ],
            'family_member_id' => [
                'nullable',
                'uuid',
                Rule::exists('family_members', 'id')->where(function ($query) use ($tenantId) {
                    $query->whereNull('deleted_at')
                        ->whereIn('family_id', function ($sub) use ($tenantId) {
                            $sub->select('id')
                                ->from('families')
                                ->where('tenant_id', $tenantId)
                                ->whereNull('deleted_at');
                        });
                }),
            ],
            'family_association' => 'nullable|in:none,existing,new',
            'acknowledge_person_match' => 'nullable|boolean',
            'use_person_id' => [
                'nullable',
                'uuid',
                Rule::exists('persons', 'id')->where('tenant_id', $tenantId),
            ],
            'relationship_to_head' => 'nullable|string|max:40',
            'person' => 'nullable|array',
            'person.first_name' => 'required_with:person|string|max:100',
            'person.middle_name' => 'nullable|string|max:100',
            'person.last_name' => 'required_with:person|string|max:100',
            'person.date_of_birth' => 'nullable|date',
            'person.place_of_birth' => 'nullable|string|max:255',
            'person.gender' => 'nullable|in:male,female,other',
            'person.father_name' => 'nullable|string|max:255',
            'person.mother_name' => 'nullable|string|max:255',
            'person.phone' => 'nullable|string|max:20',
            'person.email' => 'nullable|email|max:255',
            'person.address_line_1' => 'nullable|string|max:500',
            'person.address_line_2' => 'nullable|string|max:500',
            'person.city' => 'nullable|string|max:100',
            'person.postal_code' => 'nullable|string|max:20',
            'family' => 'nullable|array',
            'family.family_name' => 'required_with:family|string|max:255',
            'family.head_of_family' => 'nullable|string|max:255',
            'family.address_line_1' => 'nullable|string|max:500',
            'family.address_line_2' => 'nullable|string|max:500',
            'family.city' => 'nullable|string|max:100',
            'family.postal_code' => 'nullable|string|max:40',
            'family.bcc_id' => 'nullable|uuid',
            'family.notes' => 'nullable|string',
            'bcc_id' => [
                'nullable',
                'uuid',
                Rule::exists('bccs', 'id')->where('tenant_id', $tenantId),
            ],
            'recipient_name' => ($hasParticipants ? 'nullable' : 'required').'|string|max:255',
            'recipient_dob' => 'nullable|date',
            'recipient_birth_date' => 'nullable|date',
            'recipient_birth_place' => 'nullable|string|max:255',
            'baptism_date' => 'nullable|date',
            'date_administered' => 'required|date',
            'place_administered' => 'nullable|string|max:255',
            'place_classification' => ['nullable', SacramentPlaceClassification::rule()],
            'event_subtype' => 'nullable|string|max:64',
            'typed_attributes' => 'nullable|array',
            'typed_attributes.ordination_type' => 'nullable|string|max:64',
            'typed_attributes.diocese_name' => 'nullable|string|max:255',
            'typed_attributes.place_detail' => 'nullable|string|max:255',
            'recipient_gender' => 'nullable|in:male,female,other',
            'minister_name' => 'nullable|string|max:255',
            'minister_title' => 'nullable|string|max:50',
            'certificate_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('sacraments', 'certificate_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'book_number' => 'nullable|string|max:255',
            'page_number' => 'nullable|string|max:255',
            'registry_entry' => 'nullable|string|max:100',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'godparent1_name' => 'nullable|string|max:255',
            'godparent2_name' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => ['nullable', SacramentStatus::validationRule()],
            'acknowledge_duplicate_warning' => 'nullable|boolean',
            'marriage_bride_full_name' => 'nullable|string|max:255',
            'marriage_bride_father_name' => 'nullable|string|max:255',
            'marriage_bride_mother_name' => 'nullable|string|max:255',
            'marriage_bride_address' => 'nullable|string',
            'marriage_bride_church_type' => 'nullable|in:home_parish,other',
            'marriage_bride_church_name' => 'nullable|string|max:255',
            'marriage_bride_church_address' => 'nullable|string',
            'marriage_bride_diocese_name' => 'nullable|string|max:255',
            'marriage_bride_diocese_region' => 'nullable|string|max:255',
            'marriage_bride_diocese_country' => 'nullable|string|max:255',
            'marriage_groom_full_name' => 'nullable|string|max:255',
            'marriage_groom_father_name' => 'nullable|string|max:255',
            'marriage_groom_mother_name' => 'nullable|string|max:255',
            'marriage_groom_address' => 'nullable|string',
            'marriage_groom_church_type' => 'nullable|in:home_parish,other',
            'marriage_groom_church_name' => 'nullable|string|max:255',
            'marriage_groom_church_address' => 'nullable|string',
            'marriage_groom_diocese_name' => 'nullable|string|max:255',
            'marriage_groom_diocese_region' => 'nullable|string|max:255',
            'marriage_groom_diocese_country' => 'nullable|string|max:255',
            'participants' => 'nullable|array',
            'participants.*.role' => ['required_with:participants', SacramentParticipantRole::rule()],
            'participants.*.source' => ['required_with:participants', SacramentParticipantSource::rule()],
            'participants.*.sort_order' => 'nullable|integer|min:0',
            'participants.*.family_member_id' => 'nullable|uuid',
            'participants.*.person_id' => 'nullable|uuid',
            'participants.*.church_leadership_id' => 'nullable|integer',
            'participants.*.leadership_assignment_id' => 'nullable|uuid',
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
            // Clients must not supply authoritative snapshots (ADR-02).
            'participants.*.snapshot_json' => 'prohibited',
            // Medical / confession content must never be accepted (ADR-19 / ADR-20).
            'diagnosis' => 'prohibited',
            'medical_history' => 'prohibited',
            'medication' => 'prohibited',
            'disease' => 'prohibited',
            'clinical_notes' => 'prohibited',
            'confession_text' => 'prohibited',
            'sins' => 'prohibited',
            'confession_notes' => 'prohibited',
            'penance_details' => 'prohibited',
        ];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $typeId = (int) $this->input('sacrament_type_id');
            if ($typeId <= 0) {
                return;
            }

            $type = SacramentType::find($typeId);
            if (! $type) {
                return;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            if (! app(TenantSacramentSettingsService::class)->isEnabledForTenant($tenantId, $typeId)) {
                $validator->errors()->add(
                    'sacrament_type_id',
                    'This sacrament is not available for new registration in this church.'
                );

                return;
            }

            $hasParticipants = is_array($this->input('participants')) && count($this->input('participants')) > 0;
            if (! $hasParticipants && $type->requires_minister) {
                $name = trim((string) $this->input('minister_name', ''));
                if ($name === '') {
                    $validator->errors()->add('minister_name', 'Minister is required for this sacrament type.');
                }
            }

            $code = SacramentTypeCode::normalize($type->code);
            if (in_array($code, [SacramentTypeCode::BAPTISM, SacramentTypeCode::EUCHARIST], true)) {
                $label = $code === SacramentTypeCode::EUCHARIST ? 'Eucharist' : 'Baptism';
                $this->requireRecipientIdentityFields($validator, $label);
            }

            if ($code === SacramentTypeCode::MATRIMONY) {
                $this->requireMarriageIdentityFields($validator);
            }

            if ($code === SacramentTypeCode::EUCHARIST && empty($this->input('baptism_date'))) {
                $validator->errors()->add('baptism_date', 'Baptism date is required.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'sacrament_type_id' => 'sacrament type',
            'recipient_name' => 'recipient name',
            'date_administered' => 'date administered',
            'place_administered' => 'place administered',
            'minister_name' => 'minister name',
            'certificate_number' => 'certificate number',
            'baptism_date' => 'baptism date',
            'recipient_birth_date' => 'date of birth',
            'recipient_birth_place' => 'place of birth',
            'recipient_gender' => 'gender',
            'father_name' => "father's name",
            'mother_name' => "mother's name",
        ];
    }

    private function requireRecipientIdentityFields(Validator $validator, string $label): void
    {
        if (trim((string) $this->input('place_administered', '')) === '') {
            $validator->errors()->add('place_administered', "Place administered is required for {$label}.");
        }

        if (empty($this->input('recipient_birth_date'))
            && empty($this->input('person.date_of_birth'))
            && $this->resolveLinkedRecipientBirthDate() === '') {
            $validator->errors()->add('recipient_birth_date', "Date of birth is required for {$label}.");
        }

        $birthPlace = $this->firstFilledString(
            $this->input('recipient_birth_place'),
            $this->input('person.place_of_birth')
        );
        if ($birthPlace === '') {
            $validator->errors()->add('recipient_birth_place', "Place of birth is required for {$label}.");
        }

        if (empty($this->input('recipient_gender')) && empty($this->input('person.gender'))) {
            $validator->errors()->add('recipient_gender', "Gender is required for {$label}.");
        }

        $father = $this->firstFilledString(
            $this->input('father_name'),
            $this->input('person.father_name'),
            $this->participantExternalName('father')
        );
        if ($father === '') {
            $validator->errors()->add('father_name', "Father's name is required for {$label}.");
        }

        $mother = $this->firstFilledString(
            $this->input('mother_name'),
            $this->input('person.mother_name'),
            $this->participantExternalName('mother')
        );
        if ($mother === '') {
            $validator->errors()->add('mother_name', "Mother's name is required for {$label}.");
        }
    }

    private function requireMarriageIdentityFields(Validator $validator): void
    {
        if (trim((string) $this->input('place_administered', '')) === '') {
            $validator->errors()->add('place_administered', 'Place administered is required for Marriage.');
        }

        $hasParticipants = is_array($this->input('participants')) && count($this->input('participants')) > 0;
        if ($hasParticipants) {
            $this->requirePartyDobAndGender($validator, 'bride', 'bride_date_of_birth', "Bride's date of birth");
            $this->requirePartyDobAndGender($validator, 'bride', 'bride_gender', "Bride's gender", 'gender');
            $this->requirePartyDobAndGender($validator, 'groom', 'groom_date_of_birth', "Groom's date of birth");
            $this->requirePartyDobAndGender($validator, 'groom', 'groom_gender', "Groom's gender", 'gender');

            return;
        }

        if (empty($this->input('recipient_birth_date'))) {
            $validator->errors()->add('recipient_birth_date', 'Date of birth is required for Marriage.');
        }
        if (empty($this->input('recipient_gender'))) {
            $validator->errors()->add('recipient_gender', 'Gender is required for Marriage.');
        }
    }

    private function requirePartyDobAndGender(
        Validator $validator,
        string $role,
        string $errorKey,
        string $message,
        string $field = 'dob'
    ): void {
        $row = $this->participantRow($role);
        $value = $field === 'gender'
            ? trim((string) ($row['external_gender'] ?? ''))
            : trim((string) ($row['external_date_of_birth'] ?? ''));

        if ($value === '' && ! empty($row['family_member_id'])) {
            $value = $this->resolveFamilyMemberIdentityValue(
                (string) $row['family_member_id'],
                $field === 'gender' ? 'gender' : 'dob'
            );
        }

        if ($value === '') {
            $validator->errors()->add(
                $errorKey,
                $field === 'dob'
                    ? 'Date of birth is required for this participant.'
                    : "{$message} is required for Marriage."
            );
        }
    }

    private function resolveLinkedRecipientBirthDate(): string
    {
        $memberId = $this->input('family_member_id');
        if (filled($memberId)) {
            $value = $this->resolveFamilyMemberIdentityValue((string) $memberId, 'dob');
            if ($value !== '') {
                return $value;
            }
        }

        $recipient = $this->participantRow('recipient');
        if (! empty($recipient['family_member_id'])) {
            return $this->resolveFamilyMemberIdentityValue((string) $recipient['family_member_id'], 'dob');
        }

        return '';
    }

    private function resolveFamilyMemberIdentityValue(string $memberId, string $field): string
    {
        $member = FamilyMember::query()->with('person')->find($memberId);
        if (! $member) {
            return '';
        }

        if ($field === 'gender') {
            return trim((string) ($member->gender ?? $member->person?->gender ?? ''));
        }

        $birthDate = $member->date_of_birth ?? $member->person?->date_of_birth;

        return $birthDate ? $birthDate->format('Y-m-d') : '';
    }

    /** @return array<string, mixed> */
    private function participantRow(string $role): array
    {
        $participants = $this->input('participants', []);
        if (! is_array($participants)) {
            return [];
        }

        foreach ($participants as $row) {
            if (is_array($row) && strtolower((string) ($row['role'] ?? '')) === $role) {
                return $row;
            }
        }

        return [];
    }

    private function firstFilledString(mixed ...$candidates): string
    {
        foreach ($candidates as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function participantExternalName(string $role): string
    {
        $participants = $this->input('participants', []);
        if (! is_array($participants)) {
            return '';
        }

        foreach ($participants as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (strtolower((string) ($row['role'] ?? '')) === $role) {
                return trim((string) ($row['external_full_name'] ?? ''));
            }
        }

        return '';
    }

    /** @return list<string> */
    private function nullableFields(): array
    {
        return [
            'family_id', 'bcc_id', 'recipient_dob', 'recipient_birth_date',
            'recipient_birth_place', 'recipient_gender', 'baptism_date',
            'place_administered', 'place_classification',
            'event_subtype', 'minister_name',
            'minister_title', 'certificate_number', 'book_number', 'page_number', 'registry_entry',
            'father_name', 'mother_name', 'godparent1_name', 'godparent2_name',
            'witnesses', 'notes', 'status',
            'marriage_bride_full_name', 'marriage_bride_father_name', 'marriage_bride_mother_name',
            'marriage_bride_address', 'marriage_bride_church_type', 'marriage_bride_church_name',
            'marriage_bride_church_address', 'marriage_bride_diocese_name',
            'marriage_bride_diocese_region', 'marriage_bride_diocese_country',
            'marriage_groom_full_name', 'marriage_groom_father_name',
            'marriage_groom_mother_name', 'marriage_groom_address', 'marriage_groom_church_type',
            'marriage_groom_church_name', 'marriage_groom_church_address',
            'marriage_groom_diocese_name', 'marriage_groom_diocese_region', 'marriage_groom_diocese_country',
        ];
    }
}
