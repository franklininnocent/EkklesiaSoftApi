<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Requests\StoreBishopRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateBishopRequest;
use Modules\EcclesiasticalData\Http\Requests\UploadBishopImageRequest;
use Modules\EcclesiasticalData\Http\Resources\BishopResource;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class BishopController extends Controller
{
    use HandlesEcclesiasticalResponses;

    public function __construct(
        protected BishopService $service,
        protected BishopFileUploadService $fileUploadService,
        protected SuccessionService $successionService,
    ) {}

    /**
     * Display a paginated listing of bishops
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            $this->authorize('viewAny', BishopManagement::class);

            $params = $request->only([
                'search',
                'diocese_id',
                'title_id',
                'status',
                'is_active',
                'is_current',
                'sort_by',
                'sort_dir',
                'per_page',
                'page',
            ]);
            $params['page'] = $params['page'] ?? (int) $request->input('page', 1);

            $bishops = $this->service->getPaginated($params);

            return $this->ecclesiasticalSuccess([
                'data' => array_values(array_map(
                    static fn (BishopManagement $bishop) => (new BishopResource($bishop))->resolve($request),
                    $bishops->items()
                )),
                'current_page' => $bishops->currentPage(),
                'last_page' => $bishops->lastPage(),
                'per_page' => $bishops->perPage(),
                'total' => $bishops->total(),
                'from' => $bishops->firstItem(),
                'to' => $bishops->lastItem(),
                'first_page_url' => $bishops->url(1),
                'last_page_url' => $bishops->url($bishops->lastPage()),
                'next_page_url' => $bishops->nextPageUrl(),
                'prev_page_url' => $bishops->previousPageUrl(),
                'path' => $bishops->path(),
                'links' => $bishops->linkCollection()->toArray(),
            ], 'Bishops retrieved successfully');
        }, 'Failed to retrieve bishops');
    }

    /**
     * Store a newly created bishop person (optional initial appointment).
     */
    public function store(StoreBishopRequest $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            $validated = $request->validated();
            $appointment = $validated['appointment'] ?? null;
            unset($validated['appointment']);

            if (! isset($validated['archdiocese_id']) && $appointment) {
                $validated['archdiocese_id'] = $appointment['diocese_id'];
            }

            $bishop = $this->service->createPerson(
                $validated,
                (int) $request->user()->id,
                true,
            );

            if ($appointment) {
                $actorId = (int) $request->user()->id;
                $role = ! empty($appointment['canonical_role'])
                    ? CanonicalRole::from($appointment['canonical_role'])
                    : CanonicalRole::DiocesanBishop;
                $isCurrent = (bool) ($appointment['is_current'] ?? true);

                if ($role->isOrdinary() && $isCurrent) {
                    $this->successionService->replaceCurrentOrdinary(
                        (int) $appointment['diocese_id'],
                        $bishop,
                        $appointment,
                        $actorId,
                    );
                } else {
                    $this->service->createAppointmentForBishop(
                        $bishop,
                        $appointment,
                        $actorId,
                    );
                }
            }

            $bishop = $this->service->getById((string) $bishop->id);

            return $this->ecclesiasticalSuccess(
                new BishopResource($bishop),
                'Bishop created successfully',
                201
            );
        }, 'Failed to create bishop', 422);
    }

    /**
     * Display the specified bishop
     */
    public function show(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $bishop = $this->service->getById($id);
            $this->authorize('view', $bishop);

            return $this->ecclesiasticalSuccess(
                new BishopResource($bishop),
                'Bishop retrieved successfully'
            );
        }, 'Bishop not found');
    }

    /**
     * Update bishop person record (not appointment history).
     */
    public function update(UpdateBishopRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $bishop = $this->service->getById($id);
            $this->authorize('update', $bishop);
            $updated = $this->service->updatePersonRecord(
                $bishop,
                $request->validated(),
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopResource($this->service->getById((string) $updated->id)),
                'Bishop updated successfully'
            );
        }, 'Failed to update bishop', 422);
    }

    /**
     * Remove the specified bishop
     */
    public function destroy(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $bishop = BishopManagement::query()->findOrFail($id);
            $this->authorize('delete', $bishop);
            $this->service->delete($id);

            return $this->ecclesiasticalSuccess(null, 'Bishop deleted successfully');
        }, 'Failed to delete bishop', 422);
    }

    /**
     * Get bishops by diocese
     */
    public function byDiocese(Request $request, string $dioceseId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $dioceseId) {
            $this->authorize('viewAny', BishopManagement::class);

            $currentOnly = $request->boolean('current_only', true);
            $bishops = $this->service->getByDiocese($dioceseId, $currentOnly);

            return $this->ecclesiasticalSuccess(
                array_values(array_map(
                    static fn (BishopManagement $bishop) => (new BishopResource($bishop))->resolve($request),
                    $bishops->all()
                )),
                'Bishops retrieved successfully'
            );
        }, 'Failed to retrieve bishops');
    }

    /**
     * Get bishops by title
     */
    public function byTitle(Request $request, string $titleId): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $titleId) {
            $this->authorize('viewAny', BishopManagement::class);
            $bishops = $this->service->getByTitle($titleId);

            return $this->ecclesiasticalSuccess(
                BishopResource::collection($bishops),
                'Bishops retrieved successfully'
            );
        }, 'Failed to retrieve bishops');
    }

    /**
     * Get bishop statistics
     */
    public function statistics(): JsonResponse
    {
        return $this->handleEcclesiastical(function () {
            $this->authorize('viewAny', BishopManagement::class);

            return $this->ecclesiasticalSuccess(
                $this->service->getStatistics(),
                'Statistics retrieved successfully'
            );
        }, 'Failed to retrieve statistics');
    }

    /**
     * Get audit history for a bishop
     */
    public function auditHistory(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $bishop = $this->service->getById($id);
            $this->authorize('viewAudit', $bishop);

            return $this->ecclesiasticalSuccess(
                $bishop->auditHistory(),
                'Audit history retrieved successfully'
            );
        }, 'Failed to retrieve audit history');
    }

    public function uploadPhoto(UploadBishopImageRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $bishop = BishopManagement::query()->findOrFail($id);
            $path = $this->fileUploadService->uploadPhoto($bishop, $request->file('image'));
            $bishop->refresh();

            return $this->ecclesiasticalSuccess([
                'photo_path' => $path,
                'photo_url' => $bishop->photo_url,
                'photo_public_url' => $this->fileUploadService->publicUrl($path),
                'has_photo' => true,
            ], 'Bishop photo uploaded successfully');
        }, 'Failed to upload bishop photo', 422);
    }

    public function uploadCoatOfArms(UploadBishopImageRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $bishop = BishopManagement::query()->findOrFail($id);
            $path = $this->fileUploadService->uploadCoatOfArms($bishop, $request->file('image'));

            return $this->ecclesiasticalSuccess([
                'coat_of_arms_path' => $path,
                'coat_of_arms_public_url' => $this->fileUploadService->publicUrl($path),
            ], 'Coat of arms uploaded successfully');
        }, 'Failed to upload coat of arms', 422);
    }

    public function deletePhoto(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $bishop = BishopManagement::query()->findOrFail($id);
            $this->authorize('manageImages', $bishop);
            $this->fileUploadService->deletePhoto($bishop);

            return $this->ecclesiasticalSuccess(null, 'Bishop photo removed successfully');
        }, 'Failed to remove bishop photo', 422);
    }
}
