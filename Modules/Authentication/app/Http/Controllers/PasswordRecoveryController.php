<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Authentication\Http\Requests\RequestPasswordRecoveryRequest;
use Modules\Authentication\Services\PasswordRecoveryService;

class PasswordRecoveryController extends Controller
{
    public function __construct(private readonly PasswordRecoveryService $recovery)
    {
    }

    public function request(RequestPasswordRecoveryRequest $request): JsonResponse
    {
        $result = $this->recovery->requestRecovery((string) $request->input('email'));

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }
}
