<?php

namespace Modules\PastoralCare\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Support\PastoralCarePriority;
use Modules\PastoralCare\Support\PastoralCareStatus;
use Modules\PastoralCare\Support\PastoralCareType;

/**
 * @extends Factory<PastoralCareRequest>
 */
class PastoralCareRequestFactory extends Factory
{
    protected $model = PastoralCareRequest::class;

    public function definition(): array
    {
        return [
            'type' => PastoralCareType::HOME_VISIT,
            'priority' => PastoralCarePriority::ROUTINE,
            'status' => PastoralCareStatus::OPEN,
            'summary' => 'Home visit for the family',
            'notes' => null,
            'due_on' => now()->addDay()->toDateString(),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => PastoralCareStatus::OPEN]);
    }

    public function assigned(int $assigneeId, int $assignerId): static
    {
        return $this->state(fn () => [
            'status' => PastoralCareStatus::ASSIGNED,
            'assigned_to_user_id' => $assigneeId,
            'assigned_by_user_id' => $assignerId,
            'assigned_at' => now(),
        ]);
    }
}
