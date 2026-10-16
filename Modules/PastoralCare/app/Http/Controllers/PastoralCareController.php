<?php

namespace Modules\PastoralCare\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\PastoralCare\Exceptions\PastoralCareException;
use Modules\PastoralCare\Http\Requests\AssignPastoralCareRequest;
use Modules\PastoralCare\Http\Requests\StorePastoralCareRequest;
use Modules\PastoralCare\Services\PastoralCareService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PastoralCareController extends Controller
{
    public function __construct(
        private readonly PastoralCareService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->tenantId();
            $filters = [
                'status' => $request->input('status'),
                'family_id' => $request->input('family_id'),
                'assigned_to_user_id' => $request->boolean('mine')
                    ? Auth::id()
                    : $request->input('assigned_to_user_id'),
            ];
            $page = $this->service->paginate($tenantId, $filters, (int) $request->input('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => collect($page->items())->map(fn ($item) => $this->service->toArray($item))->values(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $request = $this->service->findForTenant($this->tenantId(), $id);
            $this->authorize('view', $request);

            return response()->json([
                'success' => true,
                'data' => $this->service->toArray($request),
            ]);
        } catch (PastoralCareException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function store(StorePastoralCareRequest $request): JsonResponse
    {
        try {
            $created = $this->service->create($this->tenantId(), $this->actor(), $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Visit requested.',
                'data' => $this->service->toArray($created),
            ], 201);
        } catch (PastoralCareException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function assign(AssignPastoralCareRequest $request, string $id): JsonResponse
    {
        try {
            $careRequest = $this->service->findForTenant($this->tenantId(), $id);
            $this->authorize('assign', $careRequest);

            $updated = $this->service->assign(
                $this->tenantId(),
                $this->actor(),
                $id,
                (int) $request->validated('assigned_to_user_id'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Visit assigned.',
                'data' => $this->service->toArray($updated),
            ]);
        } catch (PastoralCareException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function complete(string $id): JsonResponse
    {
        try {
            $careRequest = $this->service->findForTenant($this->tenantId(), $id);
            $this->authorize('complete', $careRequest);

            $updated = $this->service->complete($this->tenantId(), $this->actor(), $id);

            return response()->json([
                'success' => true,
                'message' => 'Visit marked done.',
                'data' => $this->service->toArray($updated),
            ]);
        } catch (PastoralCareException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function cancel(string $id): JsonResponse
    {
        try {
            $careRequest = $this->service->findForTenant($this->tenantId(), $id);
            $this->authorize('cancel', $careRequest);

            $updated = $this->service->cancel($this->tenantId(), $this->actor(), $id);

            return response()->json([
                'success' => true,
                'message' => 'Visit cancelled.',
                'data' => $this->service->toArray($updated),
            ]);
        } catch (PastoralCareException $e) {
            return $this->error($e->getMessage(), $e->status());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function staff(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->staff($this->tenantId()),
            ]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    public function dashboard(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->dashboard($this->tenantId()),
            ]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireEffectiveTenantId();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
