<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Http\Requests\Admin\SetFeatureDependenciesRequest;
use Modules\Subscriptions\Http\Requests\Admin\StoreFeatureRequest;
use Modules\Subscriptions\Http\Requests\Admin\UpdateFeatureRequest;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Services\FeatureCatalogService;
use Modules\Subscriptions\Services\SubscriptionPresenter;

class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureCatalogService $features,
        private readonly SubscriptionPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Feature::query()->with('dependencies')->ordered()->get()->map(fn (Feature $f) => $this->presenter->feature($f))->values(),
        ]);
    }

    public function store(StoreFeatureRequest $request): JsonResponse
    {
        $feature = $this->features->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->feature($feature)], 201);
    }

    public function update(UpdateFeatureRequest $request, int $feature): JsonResponse
    {
        $updated = $this->features->update(Feature::query()->findOrFail($feature), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->feature($updated)]);
    }

    public function dependencies(SetFeatureDependenciesRequest $request, int $feature): JsonResponse
    {
        $updated = $this->features->setDependencies(Feature::query()->findOrFail($feature), $request->validated('requires'), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->feature($updated)]);
    }
}
