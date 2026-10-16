<?php

namespace Modules\MassIntentions\Tests\Support;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;

trait CreatesMassIntentionTestPayload
{
    protected function massIntentionCategoryIdFor(User $user, string $code = 'THANKSGIVING'): string
    {
        Passport::actingAs($user);
        $response = $this->getJson('/api/tenant/mass-intentions/categories');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('code', $code);
        $this->assertNotNull($row, "Expected category code {$code}");

        return (string) $row['id'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function createTestCelebration(User $user, array $overrides = []): string
    {
        Passport::actingAs($user);

        $day = $overrides['celebrated_on'] ?? Carbon::now()->addWeek()->toDateString();
        unset($overrides['celebrated_on']);

        $response = $this->postJson('/api/tenant/mass-intentions/celebrations', array_merge([
            'celebrated_on' => $day,
            'celebrated_at' => '06:00',
            'place' => 'Main church',
        ], $overrides));

        $response->assertCreated();

        return (string) $response->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validMassIntentionRequestPayload(User $user, array $overrides = []): array
    {
        $categoryId = $overrides['mass_intention_category_id'] ?? $this->massIntentionCategoryIdFor($user);
        unset($overrides['mass_intention_category_id']);

        if (! isset($overrides['celebration_id'])) {
            $this->grantPermissions($user, ['mass.intentions.schedule']);
            $overrides['celebration_id'] = $this->createTestCelebration($user, [
                'celebrated_on' => $overrides['requested_date'] ?? Carbon::now()->addWeek()->toDateString(),
            ]);
        }

        unset($overrides['requested_date']);

        return array_merge([
            'beneficiary_name' => 'Maria Santos',
            'beneficiary_place' => 'Test Place',
            'mass_intention_category_id' => $categoryId,
            'intention_description' => 'Specific details for this Mass intention.',
            'mass_count' => 1,
        ], $overrides);
    }
}
