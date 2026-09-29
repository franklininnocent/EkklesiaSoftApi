<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\PersonService;
use Modules\Family\Models\Person;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassIntentionsSql;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionRequestService
{
    private const OFFICE_WORKFLOW_MESSAGE = 'This parish office workflow no longer uses review or acceptance. Create intentions as open records and close them when done.';

    public function __construct(
        private readonly PersonService $personService,
        private readonly MassIntentionAuditService $audits,
        private readonly MassIntentionCanonService $canon,
        private readonly MassIntentionOfficeCloseService $officeClose,
        private readonly MassIntentionCategoryService $categories,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, User $actor, array $data): MassIntentionRequest
    {
        $person = $this->resolveBeneficiaryPerson($tenantId, $data);
        $beneficiaryName = $this->beneficiaryNameFrom($data, $person);
        $identification = $this->resolveBeneficiaryIdentification($tenantId, $person, $data);
        $category = $this->categories->findForTenant($tenantId, (string) $data['mass_intention_category_id']);
        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenantId,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_person_id' => $person?->id,
            'beneficiary_name' => $beneficiaryName,
            'beneficiary_bcc_id' => $identification['beneficiary_bcc_id'],
            'beneficiary_bcc_name' => $identification['beneficiary_bcc_name'],
            'beneficiary_place' => $identification['beneficiary_place'],
            'mass_intention_category_id' => $category->id,
            'intention_text' => $category->name,
            'intention_description' => $this->nullableTrimmed($data['intention_description'] ?? null),
            'priest_text' => $data['priest_text'] ?? null,
            'notes' => $data['notes'] ?? null,
            'announce_name' => (bool) ($data['announce_name'] ?? true),
            'requester_name' => $data['requester_name'] ?? null,
            'requester_phone' => $data['requester_phone'] ?? null,
            'requested_date' => $data['requested_date'],
            'date_must_be_kept' => (bool) ($data['date_must_be_kept'] ?? false),
            'prohibit_transfer' => (bool) ($data['prohibit_transfer'] ?? false),
            'is_collective' => (bool) ($data['is_collective'] ?? false),
            'mass_count_requested' => (int) ($data['mass_count'] ?? 1),
            'created_by_user_id' => $actor->id,
        ]);

        $this->canon->assertCollectiveAllowed($tenantId, (bool) $request->is_collective);

        $this->audits->record($tenantId, 'request.created', $actor, $request->id);

        $request = $this->officeClose->applyAutomaticCloseIfDue($tenantId, $request->fresh());

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, string $id, array $data): MassIntentionRequest
    {
        $request = $this->findForTenant($tenantId, $id);

        if (MassIntentionStatus::isClosed($request->status)) {
            throw ValidationException::withMessages([
                'status' => 'Closed intentions cannot be edited.',
            ]);
        }

        if (! MassIntentionStatus::isOpen($request->status)) {
            throw ValidationException::withMessages([
                'status' => 'This intention cannot be edited.',
            ]);
        }

        $person = $this->resolveBeneficiaryPerson($tenantId, $data);
        $beneficiaryName = $this->beneficiaryNameFrom($data, $person);
        $identification = $this->resolveBeneficiaryIdentification($tenantId, $person, $data);
        $category = $this->categories->findForTenant($tenantId, (string) $data['mass_intention_category_id']);

        $request->fill([
            'beneficiary_person_id' => $person?->id,
            'beneficiary_name' => $beneficiaryName,
            'beneficiary_bcc_id' => $identification['beneficiary_bcc_id'],
            'beneficiary_bcc_name' => $identification['beneficiary_bcc_name'],
            'beneficiary_place' => $identification['beneficiary_place'],
            'mass_intention_category_id' => $category->id,
            'intention_text' => $category->name,
            'intention_description' => $this->nullableTrimmed($data['intention_description'] ?? null),
            'priest_text' => $data['priest_text'] ?? $request->priest_text,
            'notes' => $data['notes'] ?? $request->notes,
            'announce_name' => array_key_exists('announce_name', $data)
                ? (bool) $data['announce_name']
                : $request->announce_name,
            'requester_name' => $data['requester_name'] ?? $request->requester_name,
            'requester_phone' => $data['requester_phone'] ?? $request->requester_phone,
            'requested_date' => $data['requested_date'] ?? $request->requested_date,
            'date_must_be_kept' => array_key_exists('date_must_be_kept', $data)
                ? (bool) $data['date_must_be_kept']
                : $request->date_must_be_kept,
            'prohibit_transfer' => array_key_exists('prohibit_transfer', $data)
                ? (bool) $data['prohibit_transfer']
                : $request->prohibit_transfer,
            'is_collective' => array_key_exists('is_collective', $data)
                ? (bool) $data['is_collective']
                : $request->is_collective,
            'mass_count_requested' => array_key_exists('mass_count', $data)
                ? (int) $data['mass_count']
                : $request->mass_count_requested,
        ]);

        $this->canon->assertCollectiveAllowed($tenantId, (bool) $request->is_collective);

        $request->save();

        $this->audits->record($tenantId, 'request.updated', null, $request->id);

        return $this->officeClose->applyAutomaticCloseIfDue($tenantId, $request->fresh());
    }

    public function requestClarification(int $tenantId, User $actor, string $id, string $message): MassIntentionRequest
    {
        throw ValidationException::withMessages([
            'status' => self::OFFICE_WORKFLOW_MESSAGE,
        ]);
    }

    public function withdraw(int $tenantId, string $id): MassIntentionRequest
    {
        throw ValidationException::withMessages([
            'status' => 'Use close instead of withdraw in the parish office workflow.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function accept(int $tenantId, User $actor, string $id, array $data): MassIntentionRequest
    {
        throw ValidationException::withMessages([
            'status' => self::OFFICE_WORKFLOW_MESSAGE,
        ]);
    }

    public function findForTenant(int $tenantId, string $id): MassIntentionRequest
    {
        return MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<int, MassIntentionRequest>
     */
    public function findSimilar(int $tenantId, MassIntentionRequest $request)
    {
        if (! $request->requested_date) {
            return collect();
        }

        return MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $request->id)
            ->whereDate('requested_date', $request->requested_date)
            ->where('beneficiary_name', MassIntentionsSql::likeOperator(), $request->beneficiary_name)
            ->where('status', MassIntentionStatus::OPEN)
            ->limit(3)
            ->get();
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveBeneficiaryPerson(int $tenantId, array $data): ?Person
    {
        if (empty($data['beneficiary_person_id'])) {
            return null;
        }

        try {
            return $this->personService->resolve((string) $data['beneficiary_person_id'], $tenantId);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['person_id'])) {
                throw ValidationException::withMessages([
                    'beneficiary_person_id' => $errors['person_id'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{beneficiary_bcc_id: ?string, beneficiary_bcc_name: ?string, beneficiary_place: ?string}
     */
    private function resolveBeneficiaryIdentification(int $tenantId, ?Person $person, array $data): array
    {
        $place = $this->nullableTrimmed($data['beneficiary_place'] ?? null);

        if ($person !== null) {
            if ($place !== null) {
                throw ValidationException::withMessages([
                    'beneficiary_place' => 'Place cannot be set when a parish member is selected.',
                ]);
            }

            $snapshot = $this->personService->currentFamilyBccSnapshot($person, $tenantId);

            return [
                'beneficiary_bcc_id' => $snapshot['id'] ?? null,
                'beneficiary_bcc_name' => $snapshot['name'] ?? null,
                'beneficiary_place' => null,
            ];
        }

        if ($place === null) {
            throw ValidationException::withMessages([
                'beneficiary_place' => 'Enter the person\'s place (village, town, or locality).',
            ]);
        }

        return [
            'beneficiary_bcc_id' => null,
            'beneficiary_bcc_name' => null,
            'beneficiary_place' => $place,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function beneficiaryNameFrom(array $data, ?Person $person): string
    {
        $name = trim((string) ($data['beneficiary_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        if ($person) {
            return trim($person->full_name_display ?? "{$person->first_name} {$person->last_name}");
        }

        throw ValidationException::withMessages([
            'beneficiary_name' => 'Enter who this Mass is for.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(MassIntentionRequest $request): array
    {
        $request->loadMissing(['obligations']);

        $total = $request->mass_count_accepted ?? $request->mass_count_requested ?? 0;
        $said = $request->obligations?->where('status', MassObligationStatus::SAID)->count() ?? 0;

        return [
            'id' => $request->id,
            'status' => $request->status,
            'beneficiary_person_id' => $request->beneficiary_person_id,
            'beneficiary_name' => $request->beneficiary_name,
            'beneficiary_place' => $request->beneficiary_place,
            'beneficiary_bcc' => $this->beneficiaryBccPayload($request),
            'intention_text' => $request->intention_text,
            'mass_intention_category_id' => $request->mass_intention_category_id,
            'intention_description' => $request->intention_description,
            'notes' => $request->notes,
            'priest_text' => $request->priest_text,
            'announce_name' => $request->announce_name,
            'requester_name' => $request->requester_name,
            'requester_phone' => $request->requester_phone,
            'requested_date' => $request->requested_date?->format('Y-m-d'),
            'date_must_be_kept' => $request->date_must_be_kept,
            'prohibit_transfer' => (bool) $request->prohibit_transfer,
            'is_collective' => (bool) $request->is_collective,
            'mass_count_requested' => $request->mass_count_requested,
            'mass_count_accepted' => $request->mass_count_accepted,
            'said_progress' => $total > 0 ? ['said' => $said, 'total' => (int) $total] : null,
            'accepted_at' => $request->accepted_at?->toIso8601String(),
            'closed_at' => $request->closed_at?->toIso8601String(),
            'close_source' => $request->close_source,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: ?string, name: ?string}|null
     */
    private function beneficiaryBccPayload(MassIntentionRequest $request): ?array
    {
        $name = trim((string) ($request->beneficiary_bcc_name ?? ''));
        if ($request->beneficiary_bcc_id === null && $name === '') {
            return null;
        }

        return [
            'id' => $request->beneficiary_bcc_id,
            'name' => $name !== '' ? $name : null,
        ];
    }
}
