<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Subscriptions\Http\Requests\Admin\MigrateVersionTenantsRequest;
use Modules\Subscriptions\Http\Requests\Admin\PublishPlanVersionRequest;
use Modules\Subscriptions\Http\Requests\Admin\ReasonRequest;
use Modules\Subscriptions\Http\Requests\Admin\SetPlanEntitlementsRequest;
use Modules\Subscriptions\Http\Requests\Admin\StorePlanVersionRequest;
use Modules\Subscriptions\Http\Requests\Admin\UpdatePlanVersionRequest;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\PlanImpactService;
use Modules\Subscriptions\Services\PlanVersionService;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Subscriptions\Services\SubscriptionPresenter;

class PlanVersionController extends Controller
{
    public function __construct(
        private readonly PlanVersionService $versions,
        private readonly SubscriptionPresenter $presenter,
        private readonly PlanImpactService $impact,
        private readonly SubscriptionPlanChangeService $changes,
    ) {}

    public function store(StorePlanVersionRequest $request, int $plan): JsonResponse
    {
        $planModel = Plan::query()->catalog()->findOrFail($plan);
        $from = $request->validated('from_version_id')
            ? PlanVersion::query()->where('plan_id', $planModel->id)->findOrFail((int) $request->validated('from_version_id'))
            : null;

        $draft = $this->versions->createDraft($planModel, $request->user(), $from);

        return response()->json(['success' => true, 'data' => $this->presenter->version($draft)], 201);
    }

    public function show(int $plan, int $version): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->version($this->find($plan, $version))]);
    }

    public function update(UpdatePlanVersionRequest $request, int $plan, int $version): JsonResponse
    {
        $updated = $this->versions->updateDraft($this->find($plan, $version), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->version($updated)]);
    }

    public function entitlements(SetPlanEntitlementsRequest $request, int $plan, int $version): JsonResponse
    {
        $updated = $this->versions->setEntitlements($this->find($plan, $version), $request->validated('entitlements'), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->version($updated)]);
    }

    public function publish(PublishPlanVersionRequest $request, int $plan, int $version): JsonResponse
    {
        $effectiveFrom = $request->validated('effective_from') ? Carbon::parse($request->validated('effective_from')) : null;
        $published = $this->versions->publish($this->find($plan, $version), $request->user(), $effectiveFrom, $request->validated('reason'));

        return response()->json(['success' => true, 'data' => $this->presenter->version($published)]);
    }

    public function unschedule(ReasonRequest $request, int $plan, int $version): JsonResponse
    {
        $draft = $this->versions->unschedule($this->find($plan, $version), $request->user(), $request->validated('reason'));

        return response()->json(['success' => true, 'data' => $this->presenter->version($draft)]);
    }

    public function retire(ReasonRequest $request, int $plan, int $version): JsonResponse
    {
        $retired = $this->versions->retire($this->find($plan, $version), $request->user(), $request->validated('reason'));

        return response()->json(['success' => true, 'data' => $this->presenter->version($retired)]);
    }

    public function impact(int $plan, int $version): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->impact->versionImpact($this->find($plan, $version))]);
    }

    public function preview(int $plan, int $version): JsonResponse
    {
        $versionModel = $this->find($plan, $version);

        return response()->json(['success' => true, 'data' => $this->presenter->versionPreview($versionModel->plan, $versionModel)]);
    }

    public function migrateTenants(MigrateVersionTenantsRequest $request, int $plan, int $version): JsonResponse
    {
        $result = $this->changes->migrateVersionTenants($this->find($plan, $version), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function destroy(ReasonRequest $request, int $plan, int $version): JsonResponse
    {
        $this->versions->deleteDraft($this->find($plan, $version), $request->user());

        return response()->json(['success' => true]);
    }

    private function find(int $planId, int $versionId): PlanVersion
    {
        Plan::query()->catalog()->findOrFail($planId);

        return PlanVersion::query()->where('plan_id', $planId)->findOrFail($versionId);
    }
}
