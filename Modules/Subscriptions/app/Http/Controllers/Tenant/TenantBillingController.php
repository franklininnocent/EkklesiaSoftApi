<?php

namespace Modules\Subscriptions\Http\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Http\Requests\Tenant\CreateBillingOrderRequest;
use Modules\Subscriptions\Http\Requests\Tenant\SubmitOfflineClaimRequest;
use Modules\Subscriptions\Http\Requests\Tenant\UploadOfflineClaimEvidenceRequest;
use Modules\Subscriptions\Http\Requests\Tenant\VerifyBillingPaymentRequest;
use Modules\Subscriptions\Models\BillingCreditNote;
use Modules\Subscriptions\Models\BillingInvoiceLine;
use Modules\Subscriptions\Services\Billing\BillingInvoiceDocumentService;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineMethod;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\Billing\BillingOrderService;
use Modules\Subscriptions\Services\Billing\BillingQuoteService;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Services\Billing\BillingOfflineClaimEvidenceService;
use Modules\Subscriptions\Services\Billing\OfflinePaymentClaimService;
use Modules\Subscriptions\Services\Billing\PaymentVerificationService;
use Modules\Subscriptions\Support\TenantSubscriptionAccess;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class TenantBillingController extends Controller
{
    public function __construct(
        private readonly BillingAvailabilityPolicy $availability,
        private readonly BillingQuoteService $quotes,
        private readonly BillingOrderService $orders,
        private readonly PaymentVerificationService $verification,
        private readonly OfflinePaymentClaimService $offlineClaims,
        private readonly BillingInvoiceDocumentService $invoiceDocuments,
        private readonly BillingOfflineClaimEvidenceService $claimEvidence,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->availability->present($tenant)]);
    }

    public function quote(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $planCode = (string) $request->input('plan_code');
        $interval = strtoupper((string) $request->input('billing_interval', 'MONTHLY'));

        return response()->json(['success' => true, 'data' => $this->quotes->quote($tenant, $planCode, $interval)]);
    }

    public function createOrder(CreateBillingOrderRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        if (! $tenant || ! $user || ! $this->canManage($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $result = $this->orders->createOrder(
            $tenant,
            $user,
            $request->validated('plan_code'),
            $request->validated('billing_interval'),
            $request->idempotencyKey(),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $this->serializeOrder($result['order']),
                'checkout' => $result['checkout'],
            ],
        ]);
    }

    public function showOrder(Request $request, int $order): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $model = BillingOrder::query()->where('tenant_id', $tenant->id)->whereKey($order)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->serializeOrder($model)]);
    }

    public function verify(VerifyBillingPaymentRequest $request, int $order): JsonResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        if (! $tenant || ! $user || ! $this->canManage($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $model = BillingOrder::query()->where('tenant_id', $tenant->id)->whereKey($order)->firstOrFail();
        $result = $this->verification->verify(
            $tenant,
            $model,
            $request->validated('razorpay_payment_id'),
            $request->validated('razorpay_signature'),
            $user->id,
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $rows = BillingInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('issued_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function createPayableOrder(CreateBillingOrderRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        if (! $tenant || ! $user || ! $this->canManage($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $result = $this->orders->createPayableOrder(
            $tenant,
            $user,
            $request->validated('plan_code'),
            $request->validated('billing_interval'),
            $request->idempotencyKey().'_payable',
        );

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $this->serializeOrder($result['order']),
                'invoice_id' => $result['invoice_id'],
            ],
        ]);
    }

    public function offlineInstructions(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $decision = $this->availability->decide($tenant, BillingAction::SubmitOfflineClaim);
        if (! $decision->allowed) {
            return response()->json([
                'success' => true,
                'data' => ['allowed' => false, 'reason' => ['code' => $decision->reasonCode, 'message' => $decision->message], 'methods' => []],
            ]);
        }

        $methods = BillingOfflineMethod::query()->where('enabled', true)->orderBy('sort_order')->get()->map(fn ($m) => [
            'code' => $m->code,
            'label' => $m->label,
            'instructions' => $m->instructions,
        ]);

        return response()->json(['success' => true, 'data' => ['allowed' => true, 'methods' => $methods]]);
    }

    public function showInvoice(Request $request, int $invoice): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $model = BillingInvoice::query()->where('tenant_id', $tenant->id)->whereKey($invoice)->firstOrFail();
        $lines = BillingInvoiceLine::query()->where('billing_invoice_id', $model->id)->get();

        return response()->json(['success' => true, 'data' => ['invoice' => $model, 'lines' => $lines]]);
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $invoiceIds = BillingInvoice::query()->where('tenant_id', $tenant->id)->pluck('id');
        $notes = BillingCreditNote::query()
            ->whereIn('billing_invoice_id', $invoiceIds)
            ->orderByDesc('issued_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function downloadCreditNote(Request $request, int $creditNote)
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $decision = $this->availability->decide($tenant, BillingAction::DownloadInvoice);
        if (! $decision->allowed) {
            return response()->json(['success' => false, 'message' => $decision->message], 403);
        }

        $invoiceIds = BillingInvoice::query()->where('tenant_id', $tenant->id)->pluck('id');
        $note = BillingCreditNote::query()
            ->with('invoice')
            ->whereIn('billing_invoice_id', $invoiceIds)
            ->whereKey($creditNote)
            ->firstOrFail();

        return $this->invoiceDocuments->streamCreditNotePdf($note);
    }

    public function downloadInvoice(Request $request, int $invoice)
    {
        $tenant = $this->tenant();
        if (! $tenant || ! $this->canManage($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $decision = $this->availability->decide($tenant, BillingAction::DownloadInvoice);
        if (! $decision->allowed) {
            return response()->json(['success' => false, 'message' => $decision->message], 403);
        }

        $model = BillingInvoice::query()->where('tenant_id', $tenant->id)->whereKey($invoice)->firstOrFail();

        if ($request->string('format')->toString() === 'pdf') {
            return $this->invoiceDocuments->streamPdf($model);
        }

        return $this->invoiceDocuments->streamHtml($model);
    }

    public function uploadOfflineClaimEvidence(UploadOfflineClaimEvidenceRequest $request, int $claim): JsonResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        if (! $tenant || ! $user || ! $this->canManage($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $model = BillingOfflineClaim::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($claim)
            ->firstOrFail();

        $updated = $this->claimEvidence->attach($model, $user, $request->file('evidence'));

        return response()->json(['success' => true, 'data' => ['claim_id' => $updated->id, 'has_evidence' => $updated->evidence_storage_path !== null]]);
    }

    public function submitOfflineClaim(SubmitOfflineClaimRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        $user = $request->user();
        if (! $tenant || ! $user || ! $this->canManage($user)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $claim = $this->offlineClaims->submit($tenant, $user, $request->validated());

        return response()->json(['success' => true, 'data' => $claim], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(BillingOrder $order): array
    {
        return [
            'id' => $order->id,
            'internal_reference' => $order->internal_reference,
            'status' => $order->status,
            'gross_amount' => (string) $order->gross_amount,
            'currency_code' => $order->currency_code,
            'billing_interval' => $order->billing_interval,
            'expires_at' => $order->expires_at?->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
        ];
    }

    private function tenant(): ?Tenant
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return $tenantId ? Tenant::query()->find($tenantId) : null;
    }

    private function canManage($user): bool
    {
        return TenantSubscriptionAccess::canView($user);
    }
}
