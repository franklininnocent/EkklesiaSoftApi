<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Models\EcclesiasticalAuditLog;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\Tenants\Models\ChurchProfile;

class BishopUpdateRequestService
{
    private const PERSON_FIELDS = [
        'full_name',
        'given_name',
        'family_name',
        'religious_name',
        'date_of_birth',
        'ordained_priest_date',
        'ordained_bishop_date',
        'email',
        'phone',
        'education',
        'biography',
    ];

    private const APPOINTMENT_FIELDS = [
        'effective_date',
        'appointed_date',
        'installed_date',
        'announced_date',
        'end_reason',
    ];

    public function __construct(
        private readonly BishopDuplicateDetectionService $duplicateDetection,
        private readonly SuccessionService $successionService,
        private readonly BishopService $bishopService,
        private readonly BishopNameNormalizer $nameNormalizer,
        private readonly BishopFileUploadService $fileUploadService,
    ) {}

    /**
     * @param  array<string, mixed>  $bishopData
     * @param  array<string, mixed>  $appointmentData
     */
    public function createDraft(
        int $tenantId,
        int $dioceseId,
        BishopUpdateRequestType $type,
        array $bishopData,
        array $appointmentData,
        int $userId,
        ?int $targetBishopId = null,
    ): BishopUpdateRequest {
        $this->assertTenantOwnsDiocese($tenantId, $dioceseId);

        if ($type->isOrdinarySuggestion()) {
            $this->assertNoInFlightOrdinarySuggestion($dioceseId);
        }

        try {
            $created = BishopUpdateRequest::create([
                'tenant_id' => $tenantId,
                'diocese_id' => $dioceseId,
                'target_bishop_id' => $this->resolveTargetBishopId($type, $targetBishopId),
                'request_type' => $type,
                'proposed_bishop_data' => $this->sanitizeProposedBishopData($bishopData),
                'proposed_appointment_data' => $this->sanitizeProposedAppointmentData($appointmentData),
                'status' => BishopUpdateRequestStatus::Draft,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->inFlightOrdinaryConflict();
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(string $requestId, int $tenantId, array $data, int $expectedVersion, int $userId): BishopUpdateRequest
    {
        $request = $this->findForTenantOrFail($requestId, $tenantId);

        $this->assertVersion($request, $expectedVersion);

        if (! $request->isEditableBySubmitter()) {
            throw EcclesiasticalDomainException::conflict('This request can no longer be edited.');
        }

        $bishopData = array_key_exists('proposed_bishop_data', $data)
            ? $this->mergePreservingPendingPhoto(
                $request->proposed_bishop_data ?? [],
                $this->sanitizeProposedBishopData($data['proposed_bishop_data'] ?? [])
            )
            : $request->proposed_bishop_data;

        $appointmentData = array_key_exists('proposed_appointment_data', $data)
            ? $this->sanitizeProposedAppointmentData($data['proposed_appointment_data'] ?? [])
            : $request->proposed_appointment_data;

        $targetBishopId = array_key_exists('target_bishop_id', $data)
            ? $this->resolveTargetBishopId($request->request_type, $data['target_bishop_id'] ?? null)
            : $this->resolveTargetBishopId($request->request_type, $request->target_bishop_id);

        $request->fill([
            'proposed_bishop_data' => $bishopData,
            'proposed_appointment_data' => $appointmentData,
            'supporting_information' => $data['supporting_information'] ?? $request->supporting_information,
            'source_reference' => $data['source_reference'] ?? $request->source_reference,
            'submission_notes' => $data['submission_notes'] ?? $request->submission_notes,
            'target_bishop_id' => $targetBishopId,
            'updated_by' => $userId,
            'version' => $request->version + 1,
        ]);
        $request->save();

        return $request->fresh();
    }

    public function uploadPendingPhoto(string $requestId, int $tenantId, UploadedFile $file, int $userId): BishopUpdateRequest
    {
        $request = $this->findForTenantOrFail($requestId, $tenantId);

        if (! $request->isEditableBySubmitter()) {
            throw EcclesiasticalDomainException::conflict('This request can no longer be edited.');
        }

        $path = $this->fileUploadService->storePendingSuggestionPhoto((string) $request->id, $file);

        $payload = $request->proposed_bishop_data ?? [];
        $previousPath = $payload['pending_photo_path'] ?? null;
        if (is_string($previousPath) && $previousPath !== $path) {
            $this->fileUploadService->deleteStoredPath($previousPath);
        }

        $payload['pending_photo_path'] = $path;

        $request->update([
            'proposed_bishop_data' => $payload,
            'updated_by' => $userId,
            'version' => $request->version + 1,
        ]);

        return $request->fresh();
    }

    public function submit(string $requestId, int $tenantId, int $expectedVersion, int $userId): BishopUpdateRequest
    {
        return DB::transaction(function () use ($requestId, $tenantId, $expectedVersion, $userId) {
            $request = BishopUpdateRequest::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($requestId);

            if (! $request) {
                throw EcclesiasticalDomainException::notFound('Update request not found.');
            }

            $this->assertVersion($request, $expectedVersion);

            if (! $request->isEditableBySubmitter()) {
                throw EcclesiasticalDomainException::conflict('Only draft or clarification requests can be submitted.');
            }

            if ($request->request_type?->isOrdinarySuggestion()) {
                $this->assertNoInFlightOrdinarySuggestion((int) $request->diocese_id, (string) $request->id);
            }

            $duplicates = $this->duplicateDetection->findPotentialDuplicates(
                $request->proposed_bishop_data ?? [],
                $request->target_bishop_id
            );

            $bishopPayload = $request->proposed_bishop_data ?? [];
            $bishopPayload['_potential_duplicate_bishop_ids'] = $duplicates->pluck('id')->all();

            $previous = $request->only(['status', 'proposed_bishop_data', 'proposed_appointment_data']);

            $request->fill([
                'status' => BishopUpdateRequestStatus::Submitted,
                'submitted_by' => $userId,
                'submitted_at' => now(),
                'updated_by' => $userId,
                'version' => $request->version + 1,
                'proposed_bishop_data' => $bishopPayload,
            ]);
            $request->save();

            $this->audit($request, 'bishop_suggestion_submitted', $previous, $request->only([
                'status',
                'proposed_bishop_data',
                'proposed_appointment_data',
                'submitted_by',
                'submitted_at',
            ]));

            return $request->fresh();
        });
    }

    public function markUnderReview(string $requestId, int $reviewerId): BishopUpdateRequest
    {
        $request = BishopUpdateRequest::query()->lockForUpdate()->find($requestId);

        if (! $request) {
            throw EcclesiasticalDomainException::notFound('Update request not found.');
        }

        if ($request->status !== BishopUpdateRequestStatus::Submitted) {
            throw EcclesiasticalDomainException::conflict('Only submitted requests can be marked under review.');
        }

        $request->update([
            'status' => BishopUpdateRequestStatus::UnderReview,
            'reviewer_id' => $reviewerId,
            'updated_by' => $reviewerId,
        ]);

        return $request->fresh();
    }

    public function requestClarification(
        string $requestId,
        string $submitterFeedback,
        int $reviewerId,
        ?string $internalNotes = null,
    ): BishopUpdateRequest {
        $request = BishopUpdateRequest::query()->lockForUpdate()->find($requestId);

        if (! $request?->isReviewable()) {
            throw EcclesiasticalDomainException::conflict('Request is not in a reviewable state.');
        }

        $request->update([
            'status' => BishopUpdateRequestStatus::ChangesRequested,
            'submitter_feedback' => $submitterFeedback,
            'internal_reviewer_notes' => $internalNotes,
            'reviewer_id' => $reviewerId,
            'reviewed_at' => now(),
            'updated_by' => $reviewerId,
            'version' => $request->version + 1,
        ]);

        return $request->fresh();
    }

    public function reject(
        string $requestId,
        string $reason,
        int $reviewerId,
        int $expectedVersion,
    ): BishopUpdateRequest {
        return DB::transaction(function () use ($requestId, $reason, $reviewerId, $expectedVersion) {
            $request = BishopUpdateRequest::query()->lockForUpdate()->find($requestId);

            if (! $request) {
                throw EcclesiasticalDomainException::notFound('Update request not found.');
            }

            $this->assertVersion($request, $expectedVersion);

            if (! $request->isReviewable()) {
                throw EcclesiasticalDomainException::conflict('Request is not in a reviewable state.');
            }

            $previous = $request->only(['status', 'reviewer_comments']);

            $request->update([
                'status' => BishopUpdateRequestStatus::Rejected,
                'reviewer_comments' => $reason,
                'reviewer_id' => $reviewerId,
                'reviewed_at' => now(),
                'updated_by' => $reviewerId,
                'version' => $request->version + 1,
            ]);

            $this->audit($request, 'bishop_suggestion_rejected', $previous, [
                'status' => BishopUpdateRequestStatus::Rejected->value,
                'reviewer_id' => $reviewerId,
                'reviewer_comments' => $reason,
                'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            ], $reason);

            return $request->fresh();
        });
    }

    public function approveAndApply(
        string $requestId,
        int $expectedVersion,
        int $reviewerId,
    ): BishopUpdateRequest {
        return DB::transaction(function () use ($requestId, $expectedVersion, $reviewerId) {
            $request = BishopUpdateRequest::query()->lockForUpdate()->find($requestId);

            if (! $request) {
                throw EcclesiasticalDomainException::notFound('Update request not found.');
            }

            if ($request->status === BishopUpdateRequestStatus::Applied) {
                return $request;
            }

            $this->assertVersion($request, $expectedVersion);

            if (! $request->isReviewable()) {
                throw EcclesiasticalDomainException::conflict('Request is not in a reviewable state.');
            }

            $pendingPhotoPath = $request->proposed_bishop_data['pending_photo_path'] ?? null;
            $bishop = $this->resolveBishopForRequest($request);
            $appointmentData = $this->sanitizeProposedAppointmentData($request->proposed_appointment_data ?? []);
            $endReason = $this->resolveEndReason($appointmentData);
            unset($appointmentData['end_reason']);
            $personData = $this->sanitizeProposedBishopData($request->proposed_bishop_data ?? []);

            match ($request->request_type) {
                BishopUpdateRequestType::ChangeCurrentBishop,
                BishopUpdateRequestType::CreateBishop => $this->successionService->replaceCurrentOrdinary(
                    (int) $request->diocese_id,
                    $bishop,
                    $appointmentData,
                    $reviewerId,
                    $endReason,
                ),
                BishopUpdateRequestType::AddAuxiliary => $this->bishopService->createAppointmentForBishop(
                    $bishop,
                    array_merge($appointmentData, [
                        'diocese_id' => $request->diocese_id,
                        'canonical_role' => CanonicalRole::Auxiliary->value,
                    ]),
                    $reviewerId,
                ),
                BishopUpdateRequestType::AddCoadjutor => $this->bishopService->createAppointmentForBishop(
                    $bishop,
                    array_merge($appointmentData, [
                        'diocese_id' => $request->diocese_id,
                        'canonical_role' => CanonicalRole::Coadjutor->value,
                        'is_current' => true,
                    ]),
                    $reviewerId,
                ),
                BishopUpdateRequestType::UpdateBishop,
                BishopUpdateRequestType::UpdateImage,
                BishopUpdateRequestType::CorrectInformation => $this->bishopService->updatePersonRecord(
                    $bishop,
                    $personData,
                    $reviewerId,
                ),
                BishopUpdateRequestType::UpdateAppointment => $this->bishopService->updateCurrentAppointment(
                    $bishop,
                    (int) $request->diocese_id,
                    $appointmentData,
                    $reviewerId,
                ),
            };

            if (is_string($pendingPhotoPath) && $pendingPhotoPath !== '') {
                $this->fileUploadService->applyPendingPhotoToBishop($bishop, $pendingPhotoPath);
            }

            $previous = $request->only(['status', 'target_bishop_id']);

            $request->update([
                'status' => BishopUpdateRequestStatus::Applied,
                'target_bishop_id' => $bishop->id,
                'reviewer_id' => $reviewerId,
                'reviewed_at' => now(),
                'applied_at' => now(),
                'updated_by' => $reviewerId,
                'version' => $request->version + 1,
            ]);

            $this->audit($request, 'bishop_suggestion_approved', $previous, [
                'status' => BishopUpdateRequestStatus::Applied->value,
                'target_bishop_id' => $bishop->id,
                'reviewer_id' => $reviewerId,
                'applied_at' => $request->applied_at?->toIso8601String(),
            ]);

            return $request->fresh(['diocese', 'targetBishop']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeProposedBishopData(array $data): array
    {
        $clean = [];
        foreach (self::PERSON_FIELDS as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            $clean[$field] = $data[$field];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeProposedAppointmentData(array $data): array
    {
        $clean = [];
        foreach (self::APPOINTMENT_FIELDS as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            $clean[$field] = $data[$field];
        }

        return $clean;
    }

    private function resolveBishopForRequest(BishopUpdateRequest $request): BishopManagement
    {
        if ($request->request_type?->isOrdinarySuggestion()) {
            return $this->createPersonFromRequest($request);
        }

        if ($request->target_bishop_id) {
            return BishopManagement::query()->findOrFail($request->target_bishop_id);
        }

        return $this->createPersonFromRequest($request);
    }

    private function createPersonFromRequest(BishopUpdateRequest $request): BishopManagement
    {
        $bishopData = $this->sanitizeProposedBishopData($request->proposed_bishop_data ?? []);
        $bishopData['archdiocese_id'] = $request->diocese_id;
        $bishopData['normalized_name'] = $this->nameNormalizer->normalize($bishopData['full_name'] ?? null);

        return $this->bishopService->createPerson($bishopData, null, false);
    }

    private function resolveTargetBishopId(BishopUpdateRequestType $type, mixed $targetBishopId): ?int
    {
        if ($type->isOrdinarySuggestion()) {
            return null;
        }

        if (! $type->usesExistingBishopTarget() || $targetBishopId === null || $targetBishopId === '') {
            return null;
        }

        return (int) $targetBishopId;
    }

    private function resolveEndReason(array $appointmentData): ?AppointmentEndReason
    {
        $raw = $appointmentData['end_reason'] ?? null;
        if ($raw instanceof AppointmentEndReason) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return AppointmentEndReason::tryFrom($raw);
    }

    private function assertNoInFlightOrdinarySuggestion(int $dioceseId, ?string $exceptRequestId = null): void
    {
        $exists = BishopUpdateRequest::query()
            ->where('diocese_id', $dioceseId)
            ->whereIn('request_type', [
                BishopUpdateRequestType::ChangeCurrentBishop->value,
                BishopUpdateRequestType::CreateBishop->value,
            ])
            ->whereIn('status', BishopUpdateRequestStatus::inFlightStatuses())
            ->when($exceptRequestId, fn ($query) => $query->where('id', '!=', $exceptRequestId))
            ->exists();

        if ($exists) {
            throw $this->inFlightOrdinaryConflict();
        }
    }

    private function inFlightOrdinaryConflict(): EcclesiasticalDomainException
    {
        return EcclesiasticalDomainException::conflict(
            'A bishop suggestion for this diocese is already waiting for review. Please wait for that decision, or update the existing request.'
        );
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergePreservingPendingPhoto(array $existing, array $incoming): array
    {
        if (isset($existing['pending_photo_path']) && is_string($existing['pending_photo_path'])) {
            $incoming['pending_photo_path'] = $existing['pending_photo_path'];
        }

        return $incoming;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function audit(
        BishopUpdateRequest $request,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?string $notes = null,
    ): void {
        $user = Auth::user();

        EcclesiasticalAuditLog::create([
            'id' => Str::uuid()->toString(),
            'entity_type' => $request->getTable(),
            'entity_id' => (string) $request->id,
            'action' => $action,
            'old_values' => array_merge($oldValues ?? [], [
                'tenant_id' => $request->tenant_id,
                'diocese_id' => $request->diocese_id,
            ]),
            'new_values' => $newValues,
            'changes' => [
                'tenant_id' => $request->tenant_id,
                'diocese_id' => $request->diocese_id,
                'request_type' => $request->request_type?->value ?? $request->request_type,
                'decision' => $action,
            ],
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function assertTenantOwnsDiocese(int $tenantId, int $dioceseId): void
    {
        $profile = ChurchProfile::query()->where('tenant_id', $tenantId)->first();

        if (! $profile || (int) $profile->archdiocese_id !== $dioceseId) {
            throw EcclesiasticalDomainException::validation(
                'Church can only submit bishop updates for its own diocese.'
            );
        }
    }

    private function findForTenantOrFail(string $requestId, int $tenantId): BishopUpdateRequest
    {
        $request = BishopUpdateRequest::query()
            ->where('tenant_id', $tenantId)
            ->find($requestId);

        if (! $request) {
            throw EcclesiasticalDomainException::notFound('Update request not found.');
        }

        return $request;
    }

    private function assertVersion(BishopUpdateRequest $request, int $expectedVersion): void
    {
        if ($request->version !== $expectedVersion) {
            throw EcclesiasticalDomainException::conflict(
                'Request has been modified by another process. Please refresh and try again.',
                ['expected_version' => $expectedVersion, 'current_version' => $request->version]
            );
        }
    }
}
