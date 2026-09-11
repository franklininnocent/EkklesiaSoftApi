<?php

namespace Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\SupportTickets\Http\Requests\StoreRequestTypeCategoryRequest;
use Modules\SupportTickets\Http\Requests\StoreRequestTypeRequest;
use Modules\SupportTickets\Http\Requests\UpdateRequestTypeCategoryRequest;
use Modules\SupportTickets\Http\Requests\UpdateRequestTypeRequest;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Services\SupportRequestTypeCatalogService;

class OpsTicketCatalogController extends Controller
{
    public function __construct(
        private readonly SupportRequestTypeCatalogService $catalog,
    ) {}

    public function index(): JsonResponse
    {
        $types = SupportRequestType::query()
            ->withCount('tickets')
            ->with(['catalogCategories'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $types->map(fn (SupportRequestType $type) => $this->catalog->serializeType($type)),
        ]);
    }

    public function store(StoreRequestTypeRequest $request): JsonResponse
    {
        $type = $this->catalog->createType($request->validated());
        $type->loadCount('tickets')->load('catalogCategories');

        return response()->json([
            'success' => true,
            'data' => $this->catalog->serializeType($type),
            'message' => 'Request type created.',
        ], 201);
    }

    public function update(UpdateRequestTypeRequest $request, SupportRequestType $requestType): JsonResponse
    {
        $type = $this->catalog->updateType($requestType, $request->validated());
        $type->loadCount('tickets');

        return response()->json([
            'success' => true,
            'data' => $this->catalog->serializeType($type),
            'message' => 'Request type updated.',
        ]);
    }

    public function destroy(SupportRequestType $requestType): JsonResponse
    {
        try {
            $this->catalog->deleteType($requestType);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], (int) $e->getCode() ?: 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Request type deleted.',
        ]);
    }

    public function storeCategory(
        StoreRequestTypeCategoryRequest $request,
        SupportRequestType $requestType,
    ): JsonResponse {
        $category = $this->catalog->createCategory($requestType, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $category->only(['id', 'name', 'sort_order', 'active']),
            'message' => 'Category created.',
        ], 201);
    }

    public function updateCategory(
        UpdateRequestTypeCategoryRequest $request,
        SupportRequestType $requestType,
        SupportCategory $category,
    ): JsonResponse {
        if ((int) $category->request_type_id !== (int) $requestType->id) {
            return response()->json([
                'success' => false,
                'message' => 'Category does not belong to this request type.',
            ], 404);
        }

        $updated = $this->catalog->updateCategory($category, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $updated->only(['id', 'name', 'sort_order', 'active']),
            'message' => 'Category updated.',
        ]);
    }

    public function destroyCategory(SupportRequestType $requestType, SupportCategory $category): JsonResponse
    {
        if ((int) $category->request_type_id !== (int) $requestType->id) {
            return response()->json([
                'success' => false,
                'message' => 'Category does not belong to this request type.',
            ], 404);
        }

        try {
            $this->catalog->deleteCategory($category);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], (int) $e->getCode() ?: 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category deleted.',
        ]);
    }
}
