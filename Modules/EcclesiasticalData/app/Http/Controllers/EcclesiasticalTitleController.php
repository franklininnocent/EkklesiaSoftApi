<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Resources\EcclesiasticalTitleResource;
use Modules\EcclesiasticalData\Models\EcclesiasticalTitle;

class EcclesiasticalTitleController extends Controller
{
    use HandlesEcclesiasticalResponses;

    /**
     * Active ecclesiastical titles for dropdowns (bishops, appointments).
     */
    public function index(): JsonResponse
    {
        return $this->handleEcclesiastical(function () {
            $titles = EcclesiasticalTitle::query()
                ->active()
                ->orderByDisplay()
                ->get(['id', 'title', 'abbreviation', 'hierarchy_level', 'display_order']);

            return $this->ecclesiasticalSuccess(
                EcclesiasticalTitleResource::collection($titles),
                'Ecclesiastical titles retrieved successfully'
            );
        }, 'Failed to retrieve ecclesiastical titles');
    }
}
