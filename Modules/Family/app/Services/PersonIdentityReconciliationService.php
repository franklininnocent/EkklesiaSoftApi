<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Family\Models\PersonIdentityReconciliationLog;

class PersonIdentityReconciliationService
{
    private const ALLOWED_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'father_name', 'mother_name',
    ];

    private const FIELD_MAP = [
        'name' => ['first_name', 'middle_name', 'last_name'],
        'date_of_birth' => ['date_of_birth'],
        'gender' => ['gender'],
        'father_name' => ['father_name'],
        'mother_name' => ['mother_name'],
    ];

    public function __construct(
        private readonly PersonService $personService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function reconcile(
        Person $person,
        int|string $tenantId,
        array $data,
        ?int $userId
    ): Person {
        if ((int) $person->tenant_id !== (int) $tenantId) {
            throw ValidationException::withMessages([
                'person_id' => 'Person not found in this parish.',
            ]);
        }

        $field = (string) ($data['field'] ?? '');
        $newValue = $data['new_value'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to update canonical member information.',
            ]);
        }

        return DB::transaction(function () use ($person, $tenantId, $field, $newValue, $reason, $data, $userId) {
            $attrs = $this->buildAttributeUpdates($field, $newValue, $data);
            if ($attrs === []) {
                throw ValidationException::withMessages([
                    'field' => 'Invalid or unsupported identity field.',
                ]);
            }

            $oldValues = [];
            foreach (array_keys($attrs) as $attr) {
                $oldValues[$attr] = $person->getAttribute($attr);
            }

            $person->fill($attrs);
            $person->updated_by = $userId;
            $person->save();

            FamilyMember::query()
                ->where('person_id', $person->id)
                ->whereNull('deleted_at')
                ->get()
                ->each(function (FamilyMember $member) use ($person, $userId) {
                    $synced = $this->personService->memberIdentityFromPerson($person, $member->only([
                        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email',
                    ]));
                    $member->fill($synced);
                    $member->updated_by = $userId;
                    $member->save();
                });

            PersonIdentityReconciliationLog::query()->create([
                'tenant_id' => $tenantId,
                'person_id' => $person->id,
                'field' => $field,
                'old_value' => json_encode($oldValues),
                'new_value' => is_string($newValue) ? $newValue : json_encode($attrs),
                'source_selected' => $data['source_selected'] ?? null,
                'reason' => $reason,
                'user_id' => $userId,
            ]);

            return $person->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildAttributeUpdates(string $field, mixed $newValue, array $data): array
    {
        if ($field === 'name' && isset($data['name_parts']) && is_array($data['name_parts'])) {
            return array_intersect_key($data['name_parts'], array_flip(['first_name', 'middle_name', 'last_name']));
        }

        if (in_array($field, self::ALLOWED_FIELDS, true)) {
            return [$field => $newValue];
        }

        if (isset(self::FIELD_MAP[$field])) {
            $mapped = self::FIELD_MAP[$field][0];

            return [$mapped => $newValue];
        }

        return [];
    }
}
