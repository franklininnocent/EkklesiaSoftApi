<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\DB;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\Tenants\Models\PopeAssignment;
use Modules\Tenants\Models\PopeDetails;

class PopeLeadershipService
{
    public function __construct(
        private readonly EcclesiasticalLeadershipService $leadershipService,
    ) {}

    public function getCurrent(): ?PopeAssignment
    {
        return PopeAssignment::query()
            ->active()
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * @param  array{pope_name: string, pope_title?: ?string, pope_effective_from?: ?string, appointment_reference?: ?string, change_reason?: ?string}  $data
     */
    public function succeed(array $data, int $actorId): PopeAssignment
    {
        return DB::transaction(function () use ($data, $actorId) {
            $startDate = $data['pope_effective_from'] ?? now()->toDateString();
            $current = $this->getCurrent();

            if ($current
                && $current->pope_name === $data['pope_name']
                && ($current->pope_title ?? null) === ($data['pope_title'] ?? null)
                && $current->start_date?->toDateString() === $startDate
            ) {
                $this->syncLegacyPopeDetails($current, $actorId);

                return $current;
            }

            if ($current) {
                $current->update([
                    'end_date' => $startDate,
                    'status' => 'ended',
                    'change_reason' => $data['change_reason'] ?? 'succession',
                    'updated_by' => $actorId,
                ]);
            }

            $assignment = PopeAssignment::query()->create([
                'pope_name' => $data['pope_name'],
                'pope_title' => $data['pope_title'] ?? null,
                'photo_path' => $current?->photo_path,
                'start_date' => $startDate,
                'status' => 'active',
                'appointment_reference' => $data['appointment_reference'] ?? null,
                'change_reason' => null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->syncLegacyPopeDetails($assignment, $actorId);
            $this->leadershipService->invalidatePope();
            PopeDetails::clearCache();

            return $assignment;
        });
    }

    public function syncLegacyPopeDetails(PopeAssignment $assignment, ?int $actorId = null): PopeDetails
    {
        $popeDetails = PopeDetails::query()->orderByDesc('updated_at')->first() ?? new PopeDetails();

        if (! $popeDetails->exists) {
            $popeDetails->created_by = $actorId;
        }

        $popeDetails->fill([
            'pope_name' => $assignment->pope_name,
            'pope_title' => $assignment->pope_title,
            'pope_image_path' => $assignment->photo_path,
            'pope_effective_from' => $assignment->start_date,
            'updated_by' => $actorId,
        ]);
        $popeDetails->save();
        PopeDetails::clearCache();

        return $popeDetails;
    }

    public function updateCurrentPhoto(string $photoPath, int $actorId): ?PopeAssignment
    {
        $current = $this->getCurrent();
        if (! $current) {
            return null;
        }

        $current->update([
            'photo_path' => $photoPath,
            'updated_by' => $actorId,
        ]);

        $this->syncLegacyPopeDetails($current->fresh(), $actorId);
        $this->leadershipService->invalidatePope();

        return $current->fresh();
    }
}
