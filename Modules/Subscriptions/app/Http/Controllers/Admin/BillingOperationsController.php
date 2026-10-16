<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Models\BillingCreditNote;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingInvoiceLine;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Models\BillingRefund;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Services\Billing\BillingCreditNoteService;
use Modules\Subscriptions\Services\Billing\BillingInvoiceDocumentService;
use Modules\Subscriptions\Services\Billing\BillingOperationsSummaryService;
use Modules\Subscriptions\Services\Billing\BillingReconciliationService;
use Modules\Subscriptions\Services\Billing\BillingRefundService;
use Modules\Tenants\Models\Tenant;

class BillingOperationsController extends Controller
{
    public function __construct(
        private readonly BillingRefundService $refunds,
        private readonly BillingReconciliationService $reconciliation,
        private readonly BillingInvoiceDocumentService $invoiceDocuments,
        private readonly BillingCreditNoteService $creditNotes,
        private readonly BillingOperationsSummaryService $operationsSummary,
    ) {}

    public function ordersIndex(Request $request): JsonResponse
    {
        $q = BillingOrder::query()->with('plan:id,code,name')->orderByDesc('created_at');
        if ($tenantId = $request->integer('tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        if ($ref = $request->string('reference')->toString()) {
            $q->where('internal_reference', 'like', '%'.$ref.'%');
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        $page = $q->paginate(30);
        $page->getCollection()->transform(function (BillingOrder $order) {
            return $this->serializeOrderSummary($order);
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function orderShow(int $order): JsonResponse
    {
        $model = BillingOrder::query()->with('plan:id,code,name')->findOrFail($order);
        $attempts = BillingPaymentAttempt::query()->where('billing_order_id', $model->id)->orderByDesc('id')->get();
        $invoice = BillingInvoice::query()->where('billing_order_id', $model->id)->first();
        $tenant = Tenant::query()->find($model->tenant_id);
        $refunds = BillingRefund::query()
            ->whereIn('billing_payment_attempt_id', $attempts->pluck('id'))
            ->orderByDesc('id')
            ->get();
        $creditNotes = [];
        if ($invoice !== null) {
            $creditNotes = BillingCreditNote::query()->where('billing_invoice_id', $invoice->id)->orderByDesc('id')->get();
        }
        $webhooks = [];
        if ($model->provider_order_id) {
            $webhooks = BillingWebhookEvent::query()
                ->where('payload_summary->payment->order_id', $model->provider_order_id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'event_type', 'processing_status', 'signature_valid', 'created_at']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $this->serializeOrderSummary($model),
                'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
                'payment_attempts' => $attempts,
                'invoice' => $invoice,
                'refunds' => $refunds,
                'credit_notes' => $creditNotes,
                'webhook_events' => $webhooks,
            ],
        ]);
    }

    public function issueCreditNote(Request $request): JsonResponse
    {
        $input = $request->validate([
            'billing_invoice_id' => ['required', 'integer', 'exists:billing_invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $invoice = BillingInvoice::query()->findOrFail((int) $input['billing_invoice_id']);
        $note = $this->creditNotes->issueForInvoice(
            $invoice,
            (string) $input['amount'],
            $input['reason'],
            $request->user(),
        );

        return response()->json(['success' => true, 'data' => $note], 201);
    }

    public function invoicesIndex(Request $request): JsonResponse
    {
        $q = BillingInvoice::query()->orderByDesc('issued_at');
        if ($tenantId = $request->integer('tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        if ($number = $request->string('invoice_number')->toString()) {
            $q->where('invoice_number', 'like', '%'.$number.'%');
        }

        return response()->json(['success' => true, 'data' => $q->paginate(30)]);
    }

    public function invoiceShow(int $invoice): JsonResponse
    {
        $model = BillingInvoice::query()->findOrFail($invoice);
        $lines = BillingInvoiceLine::query()->where('billing_invoice_id', $model->id)->get();
        $claims = BillingOfflineClaim::query()->where('billing_invoice_id', $model->id)->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => ['invoice' => $model, 'lines' => $lines, 'offline_claims' => $claims],
        ]);
    }

    public function adminDownloadInvoice(Request $request, int $invoice)
    {
        $model = BillingInvoice::query()->findOrFail($invoice);
        if ($request->string('format')->toString() === 'pdf') {
            return $this->invoiceDocuments->streamPdf($model);
        }

        return $this->invoiceDocuments->streamHtml($model);
    }

    public function adminDownloadCreditNote(int $creditNote)
    {
        $model = BillingCreditNote::query()->with('invoice')->findOrFail($creditNote);

        return $this->invoiceDocuments->streamCreditNotePdf($model);
    }

    public function operationsSummary(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->operationsSummary->summarize()]);
    }

    public function refundsIndex(): JsonResponse
    {
        $rows = BillingRefund::query()->orderByDesc('created_at')->paginate(30);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function requestRefund(Request $request): JsonResponse
    {
        $input = $request->validate([
            'billing_payment_attempt_id' => ['required', 'integer', 'exists:billing_payment_attempts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $attempt = BillingPaymentAttempt::query()->findOrFail((int) $input['billing_payment_attempt_id']);
        $refund = $this->refunds->request($attempt, (string) $input['amount'], $input['reason'], $request->user());

        return response()->json(['success' => true, 'data' => $refund], 201);
    }

    public function approveRefund(Request $request, int $refund): JsonResponse
    {
        $model = BillingRefund::query()->findOrFail($refund);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $updated = $this->refunds->approveAndProcess($model, $request->user(), $input['reason']);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function webhookHealth(): JsonResponse
    {
        $pending = BillingWebhookEvent::query()->where('processing_status', 'pending')->count();
        $failed = BillingWebhookEvent::query()->where('processing_status', 'failed')->count();
        $recent = BillingWebhookEvent::query()->orderByDesc('id')->limit(10)->get([
            'id', 'event_type', 'processing_status', 'signature_valid', 'created_at',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['pending' => $pending, 'failed' => $failed, 'recent' => $recent],
        ]);
    }

    public function runReconciliation(Request $request): JsonResponse
    {
        $result = $this->reconciliation->run($request->user()?->id);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrderSummary(BillingOrder $order): array
    {
        return [
            'id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'internal_reference' => $order->internal_reference,
            'status' => $order->status,
            'billing_interval' => $order->billing_interval,
            'gross_amount' => (string) $order->gross_amount,
            'currency_code' => $order->currency_code,
            'provider_order_id' => $order->provider_order_id,
            'environment' => $order->environment,
            'plan' => $order->plan,
            'created_at' => $order->created_at?->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
        ];
    }
}
