<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ApplicationAccess\Exceptions\ApplicationAccessStreamCapacityExceededException;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamConnectionManager;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamService;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationAccessStreamController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessStreamService $stream,
        private readonly ApplicationAccessStreamConnectionManager $connections,
    ) {}

    public function stream(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        if (! $this->connections->hasCapacity()) {
            return response()->json([
                'success' => false,
                'code' => 'stream_capacity',
                'message' => 'Live stream capacity reached. Please retry shortly.',
            ], 503);
        }

        try {
            return response()->stream(function () use ($request, $user): void {
                $this->stream->run($request, $user);
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (ApplicationAccessStreamCapacityExceededException) {
            return response()->json([
                'success' => false,
                'code' => 'stream_capacity',
                'message' => 'Live stream capacity reached. Please retry shortly.',
            ], 503);
        }
    }
}
