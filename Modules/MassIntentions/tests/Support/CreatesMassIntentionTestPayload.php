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
    protected function validMassIntentionRequestPayload(User $user, array $overrides = []): array
    {
        $categoryId = $overrides['mass_intention_category_id'] ?? $this->massIntentionCategoryIdFor($user);
        unset($overrides['mass_intention_category_id']);

        return array_merge([
            'beneficiary_name' => 'Maria Santos',
            'beneficiary_place' => 'Test Place',
            'mass_intention_category_id' => $categoryId,
            'intention_description' => 'Specific details for this Mass intention.',
            'requested_date' => Carbon::now()->addWeek()->toDateString(),
            'mass_count' => 1,
        ], $overrides);
    }
}
