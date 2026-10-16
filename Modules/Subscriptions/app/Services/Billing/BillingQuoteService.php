<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\MoneyMath;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Catalog\CatalogListPriceResolver;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Support\TaxCalculator;
use Modules\Subscriptions\Support\TaxPolicy;
use Modules\Tenants\Models\Tenant;

final class BillingQuoteService
{
    public function __construct(
        private readonly BillingAvailabilityPolicy $availability,
        private readonly SubscriptionPolicyService $policies,
        private readonly CatalogListPriceResolver $prices,
        private readonly BillingPriceResolver $priceResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(Tenant $tenant, string $planCode, string $billingInterval): array
    {
        $decision = $this->availability->decide($tenant, BillingAction::QuotePurchase);
        if (! $decision->allowed) {
            throw SubscriptionException::billingDenied($decision->reasonCode, $decision->message);
        }

        $plan = Plan::query()->publiclyListed()->where('code', $planCode)->first();
        if ($plan === null) {
            throw SubscriptionException::planNotAvailable();
        }

        $version = $plan->activeVersion()->first();
        if ($version === null) {
            throw SubscriptionException::versionNotActive();
        }

        if (! in_array($billingInterval, (array) $version->billing_intervals, true)) {
            throw SubscriptionException::changeNotAllowed('This billing interval is not available for the selected plan.');
        }

        $priced = $this->priceResolver->resolveNetListPrice($tenant, $plan, $version, $billingInterval);
        $listPrice = $priced['list_net'];

        $taxMeta = TaxPolicy::resolve($version, (array) $this->policies->platformTax());
        $breakdown = TaxCalculator::breakdown($listPrice, $taxMeta['rate_percent'], $taxMeta['prices_include_tax']);
        if ($breakdown === null) {
            throw SubscriptionException::invalidState('Unable to calculate tax.');
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'version_id' => $version->id,
            ],
            'billing_interval' => $billingInterval,
            'currency_code' => $priced['currency_code'],
            'net' => $breakdown['net'],
            'tax' => $breakdown['tax'],
            'gross' => $breakdown['gross'],
            'tax_label' => $taxMeta['label'],
            'tax_rate_percent' => $breakdown['rate_percent'],
            'prices_include_tax' => $breakdown['inclusive'],
            'discount' => $priced['discount'],
            'quote_expires_at' => now()->addMinutes(15)->toIso8601String(),
        ];
    }

    public static function toMinorUnits(string $grossDecimal, string $currencyCode): int
    {
        $normalized = MoneyMath::normalize($grossDecimal);
        if (in_array(strtoupper($currencyCode), ['JPY', 'KRW', 'VND'], true)) {
            return (int) $normalized;
        }

        return (int) bcmul($normalized, '100', 0);
    }
}
