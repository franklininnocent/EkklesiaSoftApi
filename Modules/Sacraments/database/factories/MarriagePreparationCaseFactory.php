<?php

namespace Modules\Sacraments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Authentication\Models\User;
use Modules\Sacraments\Models\MarriagePreparationCase;
use Modules\Sacraments\Support\MarriagePreparationStatus;
use Modules\Tenants\Models\Tenant;

class MarriagePreparationCaseFactory extends Factory
{
    protected $model = MarriagePreparationCase::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'status' => MarriagePreparationStatus::ACTIVE,
            'inquiry_started_at' => now(),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    public function withMilestones(array $milestones = []): static
    {
        return $this->state(fn () => array_filter([
            'pre_cana_completed_at' => $milestones['pre_cana'] ?? null,
            'banns_published_at' => $milestones['banns'] ?? null,
            'canonical_docs_verified_at' => $milestones['canonical_docs'] ?? null,
        ], static fn ($value) => $value !== null));
    }
}
