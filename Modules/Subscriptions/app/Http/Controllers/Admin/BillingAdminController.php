<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOfflineMethod;
use Modules\Subscriptions\Models\BillingTenantPriceAssignment;
use Modules\Subscriptions\Models\Plan;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\Media\ImageMediaUrlSigner;
use Modules\Subscriptions\Services\Billing\BillingAuditService;
use Modules\Subscriptions\Services\Billing\OfflineReceiptAllocationService;
use Modules\Subscriptions\Services\Billing\RazorpayGatewayClient;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;
use Modules\Tenants\Models\SubscriptionSettings;

class BillingAdminController extends Controller
{
    public function __construct(
        private readonly SubscriptionPolicyService $policies,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly BillingAuditService $audit,
        private readonly OfflineReceiptAllocationService $offlineReceipts,
        private readonly ImageMediaUrlSigner $mediaUrls,
    ) {}

    public function showControls(): JsonResponse
    {
        $billing = (array) $this->policies->get('billing', []);

        return response()->json([
            'success' => true,
            'data' => [
                'billing' => $billing,
                'gateways' => BillingGatewayConfig::query()->get()->map(fn (BillingGatewayConfig $c) => [
                    'environment' => $c->environment,
                    'enabled' => $c->enabled,
                    'live_checkout_enabled' => $c->live_checkout_enabled,
                    'public_key_id' => $c->public_key_id,
                    'last_validated_at' => $c->last_validated_at?->toIso8601String(),
                    'last_validation_status' => $c->last_validation_status,
                ]),
            ],
        ]);
    }

    public function updateControls(Request $request): JsonResponse
    {
        $input = $request->validate([
            'customer_payment_actions' => ['sometimes', 'in:enabled,disabled'],
            'online_checkout' => ['sometimes', 'in:enabled,disabled,maintenance'],
            'offline_claims' => ['sometimes', 'in:enabled,disabled'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'maintenance_until' => ['nullable', 'date'],
            'maintenance_revert_to' => ['nullable', 'in:enabled,disabled'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = (array) $this->policies->get('billing', []);

        DB::transaction(function () use ($input, $before, $request) {
            $locked = SubscriptionSettings::query()->lockForUpdate()->firstOrFail();
            $policies = is_array($locked->policies) ? $locked->policies : json_decode((string) $locked->policies, true);
            if (! is_array($policies)) {
                $policies = SubscriptionCatalogDefinition::defaultPolicies();
            }
            $billing = array_replace((array) ($policies['billing'] ?? []), array_filter([
                'customer_payment_actions' => $input['customer_payment_actions'] ?? null,
                'online_checkout' => $input['online_checkout'] ?? null,
                'offline_claims' => $input['offline_claims'] ?? null,
                'maintenance_message' => $input['maintenance_message'] ?? null,
                'maintenance_until' => $input['maintenance_until'] ?? null,
                'maintenance_revert_to' => $input['maintenance_revert_to'] ?? null,
            ], fn ($v) => $v !== null));
            $billing['policy_version'] = (int) ($billing['policy_version'] ?? 1) + 1;
            $policies['billing'] = $billing;
            $locked->forceFill(['policies' => $policies])->save();
            $this->policies->forgetPoliciesMemo();

            $this->audit->record(
                'billing_controls_updated',
                $request->user()?->id,
                null,
                null,
                $input['reason'],
                $before,
                $billing,
                'billing_controls',
            );
        });

        return response()->json(['success' => true, 'data' => (array) $this->policies->get('billing', [])]);
    }

    public function updateGateway(Request $request, string $environment): JsonResponse
    {
        if (! in_array($environment, ['test', 'live'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid environment.'], 422);
        }

        $input = $request->validate([
            'public_key_id' => ['nullable', 'string', 'max:64'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'live_checkout_enabled' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $config = BillingGatewayConfig::query()->firstOrCreate(['environment' => $environment]);
        $before = ['enabled' => $config->enabled, 'public_key_id' => $config->public_key_id];

        if (array_key_exists('public_key_id', $input)) {
            $config->public_key_id = $input['public_key_id'];
        }
        if (! empty($input['api_secret'])) {
            $config->setApiSecretValue($input['api_secret']);
        }
        if (! empty($input['webhook_secret'])) {
            $config->setWebhookSecretValue($input['webhook_secret'], true);
        }
        if (array_key_exists('enabled', $input)) {
            $config->enabled = (bool) $input['enabled'];
        }
        if ($environment === 'live' && array_key_exists('live_checkout_enabled', $input)) {
            $config->live_checkout_enabled = (bool) $input['live_checkout_enabled'];
        }
        $config->save();

        $this->audit->record('gateway_updated', $request->user()?->id, null, null, $input['reason'], $before, [
            'environment' => $environment,
            'enabled' => $config->enabled,
        ]);

        return response()->json(['success' => true, 'data' => ['environment' => $environment, 'enabled' => $config->enabled]]);
    }

    public function validateGateway(string $environment): JsonResponse
    {
        $config = BillingGatewayConfig::query()->where('environment', $environment)->firstOrFail();
        $ok = $this->razorpay->ping($config);
        $config->forceFill([
            'last_validated_at' => now(),
            'last_validation_status' => $ok ? 'ok' : 'failed',
        ])->save();

        return response()->json(['success' => true, 'data' => ['ok' => $ok]]);
    }

    public function showOfflineClaim(int $claim): JsonResponse
    {
        $model = BillingOfflineClaim::query()->findOrFail($claim);
        $tenant = Tenant::query()->find($model->tenant_id);
        $evidenceUrl = null;
        if ($model->evidence_storage_path) {
            $evidenceUrl = $this->mediaUrls->displayUrl(
                $model->evidence_storage_path,
                (int) $model->tenant_id,
                fn () => true,
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'claim' => $model,
                'tenant_name' => $tenant?->name,
                'evidence_display_url' => $evidenceUrl,
            ],
        ]);
    }

    public function offlineClaimsIndex(Request $request): JsonResponse
    {
        $q = BillingOfflineClaim::query()->orderByDesc('created_at');
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        $claims = $q->paginate(30);
        $claims->getCollection()->transform(function (BillingOfflineClaim $claim) {
            $tenant = Tenant::query()->find($claim->tenant_id);

            return array_merge($claim->toArray(), [
                'tenant_name' => $tenant?->name,
            ]);
        });

        return response()->json(['success' => true, 'data' => $claims]);
    }

    public function tenantPriceAssignmentsIndex(Request $request): JsonResponse
    {
        $q = BillingTenantPriceAssignment::query()->orderByDesc('created_at');
        if ($tenantId = $request->integer('tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }

        return response()->json(['success' => true, 'data' => $q->paginate(30)]);
    }

    public function revokeTenantPriceAssignment(Request $request, int $assignment): JsonResponse
    {
        $model = BillingTenantPriceAssignment::query()->findOrFail($assignment);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model->forceFill(['active' => false])->save();
        $this->audit->record(
            'tenant_price_revoked',
            $request->user()?->id,
            null,
            (int) $model->tenant_id,
            $input['reason'],
            null,
            ['assignment_id' => $model->id],
        );

        return response()->json(['success' => true, 'data' => $model]);
    }

    public function offlineMethodsIndex(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => BillingOfflineMethod::query()->orderBy('sort_order')->get()]);
    }

    public function updateOfflineMethod(Request $request, int $method): JsonResponse
    {
        $row = BillingOfflineMethod::query()->findOrFail($method);
        $input = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'instructions' => ['sometimes', 'array'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        if (array_key_exists('enabled', $input)) {
            $row->enabled = (bool) $input['enabled'];
        }
        if (array_key_exists('instructions', $input)) {
            $row->instructions = $input['instructions'];
        }
        $row->save();
        $this->audit->record('offline_method_updated', $request->user()?->id, null, null, $input['reason'], null, [
            'method_id' => $row->id,
            'code' => $row->code,
        ]);

        return response()->json(['success' => true, 'data' => $row]);
    }

    public function approveOfflineClaim(Request $request, int $claim): JsonResponse
    {
        $model = BillingOfflineClaim::query()->findOrFail($claim);
        $input = $request->validate([
            'second_approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $approver = $request->user();
        $second = isset($input['second_approver_user_id'])
            ? User::query()->find((int) $input['second_approver_user_id'])
            : null;
        $receipt = $this->offlineReceipts->approve($model, $approver, $second);

        return response()->json(['success' => true, 'data' => ['receipt_id' => $receipt->id]]);
    }

    public function rejectOfflineClaim(Request $request, int $claim): JsonResponse
    {
        $model = BillingOfflineClaim::query()->findOrFail($claim);
        $input = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $this->offlineReceipts->reject($model, $request->user(), $input['reason']);

        return response()->json(['success' => true]);
    }

    public function requestOfflineClaimInfo(Request $request, int $claim): JsonResponse
    {
        $model = BillingOfflineClaim::query()->findOrFail($claim);
        $input = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);
        $this->offlineReceipts->requestInformation($model, $request->user(), $input['message']);

        return response()->json(['success' => true]);
    }

    public function assignTenantPrice(Request $request): JsonResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'plan_code' => ['nullable', 'string', 'max:64'],
            'billing_interval' => ['nullable', 'in:MONTHLY,ANNUAL'],
            'negotiated_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'billing_promotion_id' => ['nullable', 'integer', 'exists:billing_promotions,id'],
            'scope' => ['sometimes', 'in:next_purchase,next_invoice,future_periods,contract_amendment'],
            'renewal_policy' => ['sometimes', 'string', 'max:64'],
            'discounted_periods' => ['nullable', 'integer', 'min:1', 'max:120'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if (empty($input['negotiated_amount']) && empty($input['billing_promotion_id'])) {
            return response()->json(['success' => false, 'message' => 'Provide negotiated_amount or billing_promotion_id.'], 422);
        }

        $planId = null;
        if (! empty($input['plan_code'])) {
            $planId = Plan::query()->where('code', $input['plan_code'])->value('id');
            if ($planId === null) {
                return response()->json(['success' => false, 'message' => 'Plan not found.'], 422);
            }
        }

        $assignment = BillingTenantPriceAssignment::query()->create([
            'tenant_id' => (int) $input['tenant_id'],
            'plan_id' => $planId,
            'billing_promotion_id' => $input['billing_promotion_id'] ?? null,
            'billing_interval' => $input['billing_interval'] ?? null,
            'negotiated_amount' => $input['negotiated_amount'] ?? null,
            'currency_code' => isset($input['currency_code']) ? strtoupper($input['currency_code']) : null,
            'scope' => $input['scope'] ?? 'next_purchase',
            'renewal_policy' => $input['renewal_policy'] ?? 'list_price',
            'discounted_periods' => $input['discounted_periods'] ?? null,
            'effective_from' => $input['effective_from'] ?? null,
            'effective_until' => $input['effective_until'] ?? null,
            'active' => true,
            'authorized_by' => $request->user()?->id,
            'reason' => $input['reason'],
        ]);

        $this->audit->record(
            'tenant_price_assigned',
            $request->user()?->id,
            null,
            (int) $input['tenant_id'],
            $input['reason'],
            null,
            ['assignment_id' => $assignment->id],
        );

        return response()->json(['success' => true, 'data' => $assignment]);
    }
}
