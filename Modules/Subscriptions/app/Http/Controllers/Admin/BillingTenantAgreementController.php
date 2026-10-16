<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\TenantBillingAgreement;
use Modules\Subscriptions\Services\Billing\BillingAuditService;
use Modules\Tenants\Models\Tenant;

class BillingTenantAgreementController extends Controller
{
    public function __construct(private readonly BillingAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $q = TenantBillingAgreement::query()->orderByDesc('created_at');
        if ($tenantId = $request->integer('tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }

        $page = $q->paginate(30);
        $page->getCollection()->transform(function (TenantBillingAgreement $row) {
            $tenant = Tenant::query()->find($row->tenant_id);

            return array_merge($row->toArray(), ['tenant_name' => $tenant?->name]);
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'plan_code' => ['required', 'string', 'max:64'],
            'billing_interval' => ['required', 'in:MONTHLY,ANNUAL'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'entitlement_on_partial' => ['sometimes', 'in:when_fully_paid,on_first_confirmed_receipt'],
            'installment_schedule' => ['nullable', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $planId = Plan::query()->where('code', $input['plan_code'])->value('id');
        if ($planId === null) {
            return response()->json(['success' => false, 'message' => 'Plan not found.'], 422);
        }

        $agreement = TenantBillingAgreement::query()->create([
            'tenant_id' => (int) $input['tenant_id'],
            'plan_id' => $planId,
            'billing_interval' => $input['billing_interval'],
            'amount' => (string) $input['amount'],
            'currency_code' => strtoupper($input['currency_code']),
            'entitlement_on_partial' => $input['entitlement_on_partial'] ?? TenantBillingAgreement::ENTITLEMENT_WHEN_FULLY_PAID,
            'installment_schedule' => $input['installment_schedule'] ?? null,
            'effective_from' => $input['effective_from'] ?? null,
            'effective_until' => $input['effective_until'] ?? null,
            'authorized_by' => $request->user()?->id,
            'reason' => $input['reason'],
            'active' => true,
        ]);

        $this->audit->record(
            'tenant_billing_agreement_created',
            $request->user()?->id,
            null,
            (int) $input['tenant_id'],
            $input['reason'],
            null,
            ['agreement_id' => $agreement->id],
        );

        return response()->json(['success' => true, 'data' => $agreement], 201);
    }

    public function revoke(Request $request, int $agreement): JsonResponse
    {
        $model = TenantBillingAgreement::query()->findOrFail($agreement);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model->forceFill(['active' => false])->save();

        $this->audit->record(
            'tenant_billing_agreement_revoked',
            $request->user()?->id,
            null,
            (int) $model->tenant_id,
            $input['reason'],
            null,
            ['agreement_id' => $model->id],
        );

        return response()->json(['success' => true, 'data' => $model]);
    }
}
