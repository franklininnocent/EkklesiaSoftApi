<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\MoneyMath;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Models\BillingTenantPriceAssignment;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Catalog\CatalogListPriceResolver;
use Modules\Tenants\Models\Tenant;

/**
 * Resolves list price + optional tenant assignment / promotion into a net list price before tax.
 */
final class BillingPriceResolver
{
    public function __construct(private readonly CatalogListPriceResolver $catalog) {}

    /**
     * @return array{
     *   list_net: string,
     *   currency_code: string,
     *   discount: array<string, mixed>|null,
     *   assignment_id: int|null
     * }
     */
    public function resolveNetListPrice(Tenant $tenant, Plan $plan, PlanVersion $version, string $billingInterval): array
    {
        $terms = $this->catalog->billedTerms($plan, $version, $tenant);
        $list = $billingInterval === 'ANNUAL' ? $terms['annual_price'] : $terms['monthly_price'];
        if ($list === null) {
            throw new \InvalidArgumentException('List price missing');
        }

        $assignment = BillingTenantPriceAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->where(function ($q) use ($plan, $billingInterval) {
                $q->whereNull('plan_id')->orWhere('plan_id', $plan->id);
            })
            ->where(function ($q) use ($billingInterval) {
                $q->whereNull('billing_interval')->orWhere('billing_interval', $billingInterval);
            })
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', now());
            })
            ->orderByDesc('id')
            ->first();

        if ($assignment === null) {
            return [
                'list_net' => MoneyMath::normalize($list),
                'currency_code' => $terms['currency_code'],
                'discount' => null,
                'assignment_id' => null,
            ];
        }

        if ($assignment->negotiated_amount !== null) {
            $negotiated = MoneyMath::normalize((string) $assignment->negotiated_amount);
            $discountAmount = MoneyMath::subtract($list, $negotiated);
            if (MoneyMath::compare($discountAmount, '0') < 0) {
                $discountAmount = '0.00';
            }

            return [
                'list_net' => $negotiated,
                'currency_code' => $assignment->currency_code ?: $terms['currency_code'],
                'discount' => [
                    'type' => 'negotiated',
                    'list_price' => MoneyMath::normalize($list),
                    'amount_off' => $discountAmount,
                    'assignment_id' => $assignment->id,
                    'renewal_policy' => $assignment->renewal_policy,
                ],
                'assignment_id' => $assignment->id,
            ];
        }

        if ($assignment->billing_promotion_id) {
            $promo = BillingPromotion::query()->find($assignment->billing_promotion_id);
            if ($promo && $promo->status === BillingPromotion::STATUS_ACTIVE) {
                $net = $this->applyPromotion($list, $promo);

                return [
                    'list_net' => $net,
                    'currency_code' => $terms['currency_code'],
                    'discount' => [
                        'type' => 'promotion',
                        'code' => $promo->code,
                        'promotion_id' => $promo->id,
                        'assignment_id' => $assignment->id,
                    ],
                    'assignment_id' => $assignment->id,
                ];
            }
        }

        return [
            'list_net' => MoneyMath::normalize($list),
            'currency_code' => $terms['currency_code'],
            'discount' => null,
            'assignment_id' => null,
        ];
    }

    private function applyPromotion(string $listPrice, BillingPromotion $promo): string
    {
        $list = MoneyMath::normalize($listPrice);
        $type = (string) $promo->discount_type;
        $value = MoneyMath::normalize((string) ($promo->discount_value ?? '0'));

        return match ($type) {
            'percent' => MoneyMath::subtract($list, MoneyMath::normalize(bcdiv(bcmul($list, $value, 6), '100', 6))),
            'fixed_amount' => MoneyMath::max('0.00', MoneyMath::subtract($list, $value)),
            'fixed_price' => $value,
            default => $list,
        };
    }
}
