<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Modules\Subscriptions\Http\Requests\Admin\PreviewSubscriptionTaxRequest;
use Modules\Subscriptions\Http\Requests\Admin\UpdateSubscriptionPoliciesRequest;
use Modules\Subscriptions\Http\Requests\Admin\UpdateSubscriptionTaxRequest;
use Modules\Subscriptions\Services\SubscriptionPolicyService;

class SubscriptionPolicyController extends Controller
{
    public function __construct(private readonly SubscriptionPolicyService $policies) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->policies->present()]);
    }

    public function update(UpdateSubscriptionPoliciesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $data = $this->policies->update(Arr::except($validated, ['reason']), $request->user(), $validated['reason'] ?? null);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function showTax(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->policies->presentTax()]);
    }

    public function updateTax(UpdateSubscriptionTaxRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $data = $this->policies->updateTax(
            Arr::only($validated, ['tax']),
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function previewTax(PreviewSubscriptionTaxRequest $request): JsonResponse
    {
        $breakdown = $this->policies->previewTax($request->validated());

        return response()->json(['success' => true, 'data' => $breakdown]);
    }
}
