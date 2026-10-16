<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Models\Plan;

final class BillingPromotionService
{
    public function __construct(private readonly BillingAuditService $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $actorId): BillingPromotion
    {
        $code = strtoupper(Str::slug((string) ($input['code'] ?? $input['name'] ?? ''), '_'));
        if ($code === '') {
            throw SubscriptionException::changeNotAllowed('Promotion code is required.');
        }

        return DB::transaction(function () use ($input, $code, $actorId) {
            $promo = BillingPromotion::query()->create([
                'code' => $code,
                'name' => (string) $input['name'],
                'status' => BillingPromotion::STATUS_DRAFT,
                'discount_type' => (string) $input['discount_type'],
                'discount_value' => $input['discount_value'] ?? null,
                'currency_code' => isset($input['currency_code']) ? strtoupper((string) $input['currency_code']) : null,
                'discounted_periods' => $input['discounted_periods'] ?? null,
                'renewal_policy' => $input['renewal_policy'] ?? 'list_price',
                'requires_assignment' => (bool) ($input['requires_assignment'] ?? true),
                'max_redemptions' => $input['max_redemptions'] ?? null,
                'starts_at' => $input['starts_at'] ?? null,
                'ends_at' => $input['ends_at'] ?? null,
                'internal_note' => $input['internal_note'] ?? null,
            ]);

            $this->syncPlans($promo, (array) ($input['plan_eligibility'] ?? []));
            $this->audit->record('promotion_created', $actorId, null, null, null, null, ['promotion_id' => $promo->id]);

            return $promo->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(BillingPromotion $promo, array $input, ?int $actorId, string $reason): BillingPromotion
    {
        if ($promo->status === BillingPromotion::STATUS_ARCHIVED) {
            throw SubscriptionException::invalidState('Archived promotions cannot be edited.');
        }

        return DB::transaction(function () use ($promo, $input, $actorId, $reason) {
            $before = $promo->only(['name', 'status', 'discount_type', 'discount_value']);
            $promo->forceFill(array_filter([
                'name' => $input['name'] ?? null,
                'discount_type' => $input['discount_type'] ?? null,
                'discount_value' => array_key_exists('discount_value', $input) ? $input['discount_value'] : null,
                'currency_code' => isset($input['currency_code']) ? strtoupper((string) $input['currency_code']) : null,
                'discounted_periods' => $input['discounted_periods'] ?? null,
                'renewal_policy' => $input['renewal_policy'] ?? null,
                'requires_assignment' => array_key_exists('requires_assignment', $input) ? (bool) $input['requires_assignment'] : null,
                'max_redemptions' => $input['max_redemptions'] ?? null,
                'starts_at' => $input['starts_at'] ?? null,
                'ends_at' => $input['ends_at'] ?? null,
                'internal_note' => $input['internal_note'] ?? null,
            ], fn ($v) => $v !== null))->save();

            if (array_key_exists('plan_eligibility', $input)) {
                $this->syncPlans($promo, (array) $input['plan_eligibility']);
            }

            $this->audit->record('promotion_updated', $actorId, null, null, $reason, $before, $promo->only(['name', 'status']));

            return $promo->fresh();
        });
    }

    public function setStatus(BillingPromotion $promo, string $status, ?int $actorId, string $reason): BillingPromotion
    {
        $allowed = [BillingPromotion::STATUS_ACTIVE, BillingPromotion::STATUS_PAUSED, BillingPromotion::STATUS_ARCHIVED];
        if (! in_array($status, $allowed, true)) {
            throw SubscriptionException::changeNotAllowed('Invalid promotion status.');
        }

        $promo->forceFill(['status' => $status])->save();
        $this->audit->record('promotion_status_changed', $actorId, null, null, $reason, null, [
            'promotion_id' => $promo->id,
            'status' => $status,
        ]);

        return $promo->fresh();
    }

    /**
     * @param  list<array{plan_code: string, billing_interval?: string|null}>  $rows
     */
    private function syncPlans(BillingPromotion $promo, array $rows): void
    {
        DB::table('billing_promotion_plans')->where('billing_promotion_id', $promo->id)->delete();
        foreach ($rows as $row) {
            $planId = Plan::query()->where('code', $row['plan_code'])->value('id');
            if ($planId === null) {
                continue;
            }
            DB::table('billing_promotion_plans')->insert([
                'billing_promotion_id' => $promo->id,
                'plan_id' => $planId,
                'billing_interval' => $row['billing_interval'] ?? null,
            ]);
        }
    }
}
