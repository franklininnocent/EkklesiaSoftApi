<?php

namespace Modules\EcclesiasticalData\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Throwable;

trait HandlesEcclesiasticalResponses
{
    protected function ecclesiasticalSuccess(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        $payload = ['success' => true, 'message' => $message];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    protected function ecclesiasticalError(string $message, int $status = 500, ?string $error = null, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    protected function handleEcclesiastical(callable $callback, string $failureMessage, int $notFoundStatus = 404): JsonResponse
    {
        try {
            return $callback();
        } catch (EcclesiasticalDomainException $e) {
            return $this->ecclesiasticalError(
                $e->getMessage(),
                $e->httpStatus,
                $e->getMessage(),
                $e->errors,
            );
        } catch (InvalidArgumentException $e) {
            return $this->ecclesiasticalError(
                $e->getMessage(),
                422,
                $e->getMessage(),
                ['image' => [$e->getMessage()]],
            );
        } catch (ModelNotFoundException $e) {
            return $this->ecclesiasticalError($failureMessage, 404, $e->getMessage());
        } catch (Throwable $e) {
            if ($notFoundStatus === 404 && str_contains(strtolower($e->getMessage()), 'not found')) {
                return $this->ecclesiasticalError($failureMessage, 404, $e->getMessage());
            }

            return $this->ecclesiasticalError($failureMessage, 500, $e->getMessage());
        }
    }

    protected function boundedPerPage(Request $request, int $default = 20, int $max = 100): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }
}
