<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Http\Requests\StoreApplicationIpBlockRequest;
use Modules\ApplicationAccess\Http\Resources\ApplicationIpBlockRuleResource;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\ApplicationAccess\Services\ApplicationIpBlockService;
use Modules\Authentication\Models\User;

class ApplicationAccessIpBlockController extends Controller
{
    public function __construct(
        private readonly ApplicationIpBlockService $ipBlocks,
    ) {}

    public function store(StoreApplicationIpBlockRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $rule = $this->ipBlocks->create(
            $actor,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'success' => true,
            'data' => new ApplicationIpBlockRuleResource($rule),
            'message' => 'IP block rule created.',
        ], 201);
    }

    public function revoke(string $id): JsonResponse
    {
        $rule = ApplicationIpBlockRule::query()->find($id);
        if (! $rule) {
            return response()->json([
                'success' => false,
                'message' => 'IP block rule not found.',
            ], 404);
        }

        $actor = request()->user();
        if (! $actor instanceof User) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $rule = $this->ipBlocks->revoke($actor, $rule);

        return response()->json([
            'success' => true,
            'data' => new ApplicationIpBlockRuleResource($rule),
            'message' => 'IP block rule revoked.',
        ]);
    }
}
