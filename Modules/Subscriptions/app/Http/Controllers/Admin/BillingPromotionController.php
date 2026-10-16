<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Services\Billing\BillingPromotionService;

class BillingPromotionController extends Controller
{
    public function __construct(private readonly BillingPromotionService $promotions) {}

    public function index(Request $request): JsonResponse
    {
        $q = BillingPromotion::query()->orderByDesc('updated_at');
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%');
            });
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        return response()->json(['success' => true, 'data' => $q->paginate(30)]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'discount_type' => ['required', 'in:percent,fixed_amount,fixed_price'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'discounted_periods' => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_policy' => ['nullable', 'string', 'max:64'],
            'requires_assignment' => ['sometimes', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'plan_eligibility' => ['nullable', 'array'],
            'plan_eligibility.*.plan_code' => ['required_with:plan_eligibility', 'string'],
            'plan_eligibility.*.billing_interval' => ['nullable', 'in:MONTHLY,ANNUAL'],
        ]);

        $promo = $this->promotions->create($input, $request->user()?->id);

        return response()->json(['success' => true, 'data' => $promo], 201);
    }

    public function show(int $promotion): JsonResponse
    {
        $model = BillingPromotion::query()->findOrFail($promotion);
        $plans = DB::table('billing_promotion_plans')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'billing_promotion_plans.plan_id')
            ->where('billing_promotion_id', $model->id)
            ->select('subscription_plans.code as plan_code', 'billing_promotion_plans.billing_interval')
            ->get();

        return response()->json(['success' => true, 'data' => ['promotion' => $model, 'plan_eligibility' => $plans]]);
    }

    public function update(Request $request, int $promotion): JsonResponse
    {
        $model = BillingPromotion::query()->findOrFail($promotion);
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'discount_type' => ['sometimes', 'in:percent,fixed_amount,fixed_price'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'discounted_periods' => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_policy' => ['nullable', 'string', 'max:64'],
            'requires_assignment' => ['sometimes', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'plan_eligibility' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $promo = $this->promotions->update($model, $input, $request->user()?->id, $input['reason']);

        return response()->json(['success' => true, 'data' => $promo]);
    }

    public function setStatus(Request $request, int $promotion): JsonResponse
    {
        $model = BillingPromotion::query()->findOrFail($promotion);
        $input = $request->validate([
            'status' => ['required', 'in:active,paused,archived'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $promo = $this->promotions->setStatus($model, $input['status'], $request->user()?->id, $input['reason']);

        return response()->json(['success' => true, 'data' => $promo]);
    }
}
