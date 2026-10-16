<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Models\BillingInvestigationItem;
use Modules\Subscriptions\Services\Billing\BillingInvestigationService;
use Modules\Tenants\Models\Tenant;

class BillingInvestigationController extends Controller
{
    public function __construct(private readonly BillingInvestigationService $investigations) {}

    public function index(Request $request): JsonResponse
    {
        $q = BillingInvestigationItem::query()->orderByDesc('created_at');
        if ($request->string('status')->toString()) {
            $q->where('status', $request->string('status')->toString());
        }
        $page = $q->paginate(30);
        $page->getCollection()->transform(function (BillingInvestigationItem $item) {
            $tenant = $item->tenant_id ? Tenant::query()->find($item->tenant_id) : null;

            return array_merge($item->toArray(), ['tenant_name' => $tenant?->name]);
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function resolve(Request $request, int $investigation): JsonResponse
    {
        $item = BillingInvestigationItem::query()->findOrFail($investigation);
        $input = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $updated = $this->investigations->resolve($item, $request->user(), $input['note']);

        return response()->json(['success' => true, 'data' => $updated]);
    }
}
