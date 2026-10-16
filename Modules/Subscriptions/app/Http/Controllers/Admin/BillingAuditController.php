<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Models\BillingAuditEvent;

class BillingAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = BillingAuditEvent::query()->orderByDesc('created_at');
        if ($tenantId = $request->integer('tenant_id')) {
            $q->where('tenant_id', $tenantId);
        }
        if ($action = $request->string('action')->toString()) {
            $q->where('action', $action);
        }

        return response()->json(['success' => true, 'data' => $q->paginate(50)]);
    }
}
