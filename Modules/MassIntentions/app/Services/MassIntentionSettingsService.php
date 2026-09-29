<?php

namespace Modules\MassIntentions\Services;

use Modules\MassIntentions\Models\MassIntentionSetting;

class MassIntentionSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function forTenant(int $tenantId): MassIntentionSetting
    {
        return MassIntentionSetting::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['review_days' => 3]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrCreate(int $tenantId): array
    {
        return $this->toArray($this->forTenant($tenantId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(int $tenantId, array $data): array
    {
        $settings = $this->forTenant($tenantId);

        $settings->fill([
            'suggested_offering_amount' => array_key_exists('suggested_offering_amount', $data)
                ? $data['suggested_offering_amount']
                : $settings->suggested_offering_amount,
            'review_days' => array_key_exists('review_days', $data)
                ? (int) $data['review_days']
                : $settings->review_days,
            'provincial_collective_authorized' => array_key_exists('provincial_collective_authorized', $data)
                ? (bool) $data['provincial_collective_authorized']
                : $settings->provincial_collective_authorized,
            'categories' => array_key_exists('categories', $data)
                ? $data['categories']
                : $settings->categories,
        ]);
        $settings->save();

        return $this->toArray($settings->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(MassIntentionSetting $settings): array
    {
        return [
            'suggested_offering_amount' => $settings->suggested_offering_amount,
            'review_days' => (int) $settings->review_days,
            'provincial_collective_authorized' => (bool) $settings->provincial_collective_authorized,
            'categories' => $settings->categories ?? [],
        ];
    }
}
