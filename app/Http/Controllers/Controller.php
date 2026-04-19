<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function success(mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'statusCode' => $statusCode,
            'statusText' => 'success',
            'data' => $data,
        ], $statusCode);
    }

    protected function error(string $message, int $statusCode = 400, mixed $errors = null): JsonResponse
    {
        $payload = ['message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json([
            'statusCode' => $statusCode,
            'statusText' => 'failed',
            'data' => $payload,
        ], $statusCode);
    }

    protected function paginated(mixed $paginator, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $extra), 200);
    }
}
