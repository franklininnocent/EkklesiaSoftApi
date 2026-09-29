<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Support\TenantContext;

class UpdateSacramentRequest extends FormRequest
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

        $this->replace($data);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $id = (int) $this->route('id');

        return [
            'sacrament_type_id' => 'sometimes|exists:sacrament_types,id',
            'family_id' => [
                'nullable',
                'uuid',
                Rule::exists('families', 'id')->where('tenant_id', $tenantId),
            ],
            'bcc_id' => [
                'nullable',
                'uuid',
                Rule::exists('bccs', 'id')->where('tenant_id', $tenantId),
            ],
            'recipient_name' => 'sometimes|string|max:255',
            'recipient_dob' => 'nullable|date',
            'recipient_birth_date' => 'nullable|date',
            'recipient_birth_place' => 'nullable|string|max:255',
            'baptism_date' => 'nullable|date',
            'date_administered' => 'sometimes|date',
            'place_administered' => 'nullable|string|max:255',
            'recipient_gender' => 'nullable|in:male,female,other',
            'minister_name' => 'nullable|string|max:255',
            'minister_title' => 'nullable|string|max:50',
            'certificate_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('sacraments', 'certificate_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($id),
            ],
            'book_number' => 'nullable|string|max:255',
            'page_number' => 'nullable|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'godparent1_name' => 'nullable|string|max:255',
            'godparent2_name' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => ['nullable', SacramentStatus::validationRule()],
            'marriage_bride_full_name' => 'nullable|string|max:255',
            'marriage_bride_father_name' => 'nullable|string|max:255',
            'marriage_bride_mother_name' => 'nullable|string|max:255',
            'marriage_bride_address' => 'nullable|string',
            'marriage_bride_church_type' => 'nullable|in:home_parish,other',
            'marriage_bride_church_name' => 'nullable|string|max:255',
            'marriage_bride_church_address' => 'nullable|string',
            'marriage_groom_full_name' => 'nullable|string|max:255',
            'marriage_groom_father_name' => 'nullable|string|max:255',
            'marriage_groom_mother_name' => 'nullable|string|max:255',
            'marriage_groom_address' => 'nullable|string',
            'marriage_groom_church_type' => 'nullable|in:home_parish,other',
            'marriage_groom_church_name' => 'nullable|string|max:255',
            'marriage_groom_church_address' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $typeId = $this->input('sacrament_type_id');
            if ($typeId === null) {
                return;
            }

            $typeId = (int) $typeId;
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $existingTypeId = Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $this->route('id'))
                ->value('sacrament_type_id');

            // Historical records may keep their original type after it is turned off.
            // Changing to a different type must use a church-active sacrament.
            if ((int) $existingTypeId !== $typeId
                && ! app(TenantSacramentSettingsService::class)->isEnabledForTenant($tenantId, $typeId)) {
                $validator->errors()->add(
                    'sacrament_type_id',
                    'This sacrament is not available for new registration in this church.'
                );

                return;
            }

            $type = SacramentType::find($typeId);
            if ($type && $type->requires_minister) {
                $name = trim((string) $this->input('minister_name', ''));
                // On update, only enforce when minister_name is present in payload or type is changing.
                if ($this->exists('minister_name') && $name === '') {
                    $validator->errors()->add('minister_name', 'Minister is required for this sacrament type.');
                }
            }
        });
    }

    /** @return list<string> */
    private function nullableFields(): array
    {
        return [
            'family_id', 'bcc_id', 'recipient_dob', 'recipient_birth_date',
            'recipient_birth_place', 'recipient_gender', 'baptism_date',
            'place_administered', 'minister_name',
            'minister_title', 'certificate_number', 'book_number', 'page_number',
            'father_name', 'mother_name', 'godparent1_name', 'godparent2_name',
            'witnesses', 'notes', 'status',
            'marriage_bride_full_name', 'marriage_bride_father_name', 'marriage_bride_mother_name',
            'marriage_bride_address', 'marriage_bride_church_type', 'marriage_bride_church_name',
            'marriage_bride_church_address', 'marriage_groom_full_name', 'marriage_groom_father_name',
            'marriage_groom_mother_name', 'marriage_groom_address', 'marriage_groom_church_type',
            'marriage_groom_church_name', 'marriage_groom_church_address',
        ];
    }
}
