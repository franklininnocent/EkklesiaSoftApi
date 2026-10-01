<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\PersonService;
use Modules\Family\Models\Person;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassIntentionsSql;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionRequestService
{
    private const OFFICE_WORKFLOW_MESSAGE = 'This parish office workflow no longer uses review or acceptance. Create intentions as open records and close them when done.';

    /** @var array<string, array<string, mixed>> */
    private array $listMassCelebrationByRequestId = [];

    public function __construct(
        private readonly PersonService $personService,
        private readonly MassIntentionAuditService $audits,
        private readonly MassIntentionCanonService $canon,
        private readonly MassIntentionOfficeCloseService $officeClose,
        private readonly MassIntentionCategoryService $categories,
        private readonly MassIntentionAssignmentService $assignments,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, User $actor, array $data): MassIntentionRequest
    {
        $celebrationId = (string) $data['celebration_id'];
        $person = $this->resolveBeneficiaryPerson($tenantId, $data);
        $beneficiaryName = $this->beneficiaryNameFrom($data, $person);
        $identification = $this->resolveBeneficiaryIdentification($tenantId, $person, $data);
        $category = $this->categories->findForTenant($tenantId, (string) $data['mass_intention_category_id']);

        $request = DB::transaction(function () use ($tenantId, $actor, $data, $celebrationId, $person, $beneficiaryName, $identification, $category): MassIntentionRequest {
            $celebration = $this->assignments->lockCelebrationForAssignment($tenantId, $celebrationId);

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
                'requested_date' => $celebration->celebrated_on,
                'date_must_be_kept' => (bool) ($data['date_must_be_kept'] ?? false),
                'prohibit_transfer' => (bool) ($data['prohibit_transfer'] ?? false),
                'is_collective' => (bool) ($data['is_collective'] ?? false),
                'mass_count_requested' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->canon->assertCollectiveAllowed($tenantId, (bool) $request->is_collective);

            $this->assignments->createObligationAndAssignment($tenantId, $actor, $request, $celebration);

            $this->audits->record($tenantId, 'request.created', $actor, $request->id, $celebration->id, [
                'mass' => MassCelebrationEligibility::massSnapshot($celebration),
                'intention_category' => $category->name,
            ]);

            return $request;
        });

        return $this->officeClose->applyAutomaticCloseIfDue($tenantId, $request->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, User $actor, string $id, array $data): MassIntentionRequest
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

        if (! $this->assignments->tenantHasActiveAssignment($tenantId, $request->id)) {
            if (empty($data['celebration_id'])) {
                throw ValidationException::withMessages([
                    'celebration_id' => 'Select the Mass this intention belongs to.',
                ]);
            }
            $this->assignments->assignLegacyOpenIntention($tenantId, $actor, $request->fresh(), (string) $data['celebration_id']);
        }

        $this->audits->record($tenantId, 'request.updated', $actor, $request->id);

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
     * @param  iterable<MassIntentionRequest>  $requests
     */
    public function preloadMassCelebrationsForList(int $tenantId, iterable $requests): void
    {
        $this->listMassCelebrationByRequestId = [];
        $collection = $requests instanceof \Illuminate\Support\Collection
            ? $requests
            : collect($requests);
        if ($collection->isEmpty()) {
            return;
        }

        $requestIds = $collection->pluck('id')->map(fn ($id) => (string) $id)->all();
        $celebrationIdByRequest = $this->assignments->activeCelebrationIdsForRequests($tenantId, $requestIds);
        if ($celebrationIdByRequest === []) {
            return;
        }

        $celebrations = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($celebrationIdByRequest)))
            ->get()
            ->keyBy('id');

        foreach ($celebrationIdByRequest as $requestId => $celebrationId) {
            $celebration = $celebrations->get($celebrationId);
            if ($celebration === null) {
                continue;
            }
            $this->listMassCelebrationByRequestId[$requestId] = array_merge(
                MassCelebrationEligibility::massSnapshot($celebration),
                [
                    'status' => $celebration->status,
                    'generation_status' => $celebration->generation_status,
                    'suppression_reason' => $celebration->suppression_reason,
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    /** Parish-office list / register export: Mass day and time (matches UI `massIntentionListMass`). */
    public function officeRegisterMassLabel(MassIntentionRequest $request): string
    {
        $massPayload = $this->massPayloadForRequest($request);
        if ($massPayload === null) {
            return MassIntentionStatus::isOpen($request->status) ? 'Needs a Mass' : '—';
        }

        $celebratedOn = $massPayload['celebrated_on'] ?? null;
        if ($celebratedOn === null || $celebratedOn === '') {
            return '—';
        }

        return $this->formatOfficeRegisterMassDayTime(
            (string) $celebratedOn,
            isset($massPayload['celebrated_at']) ? (string) $massPayload['celebrated_at'] : null,
        );
    }

    public function toArray(MassIntentionRequest $request): array
    {
        $request->loadMissing(['obligations']);

        $total = $request->mass_count_accepted ?? $request->mass_count_requested ?? 0;
        $said = $request->obligations?->where('status', MassObligationStatus::SAID)->count() ?? 0;

        $massPayload = $this->massPayloadForRequest($request);
        $needsAMass = $massPayload === null && MassIntentionStatus::isOpen($request->status);

        return [
            'id' => $request->id,
            'status' => $request->status,
            'needs_a_mass' => $needsAMass,
            'mass_celebration' => $massPayload,
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
     * @return array<string, mixed>|null
     */
    private function massPayloadForRequest(MassIntentionRequest $request): ?array
    {
        $requestId = (string) $request->id;
        if (isset($this->listMassCelebrationByRequestId[$requestId])) {
            return $this->listMassCelebrationByRequestId[$requestId];
        }

        $active = $this->assignments->activeAssignmentForRequest((int) $request->tenant_id, $request->id);
        if ($active === null) {
            return null;
        }

        $celebration = MassCelebration::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('id', $active['celebration_id'])
            ->first();

        if ($celebration === null) {
            return null;
        }

        return array_merge(
            MassCelebrationEligibility::massSnapshot($celebration),
            [
                'status' => $celebration->status,
                'generation_status' => $celebration->generation_status,
                'suppression_reason' => $celebration->suppression_reason,
            ]
        );
    }

    /**
     * @return array{id: ?string, name: ?string}|null
     */
    private function formatOfficeRegisterMassDayTime(string $celebratedOn, ?string $celebratedAt): string
    {
        try {
            $date = Carbon::parse($celebratedOn);
        } catch (\Throwable) {
            $date = null;
        }

        $parts = [];
        if ($date !== null) {
            $parts[] = $date->format('l');
        }
        $parts[] = $celebratedOn;

        $timeLabel = $this->formatCelebrationTime12Hour($celebratedAt);
        if ($timeLabel !== '') {
            $parts[] = $timeLabel;
        }

        return implode(' ', $parts);
    }

    private function formatCelebrationTime12Hour(?string $celebratedAt): string
    {
        if ($celebratedAt === null || trim($celebratedAt) === '') {
            return '';
        }

        $normalized = substr(trim($celebratedAt), 0, 5);
        try {
            return Carbon::createFromFormat('H:i', $normalized)->format('g:i A');
        } catch (\Throwable) {
            return $normalized;
        }
    }

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
