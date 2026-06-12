<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'superadmin' => \App\Http\Middleware\SuperAdmin::class,
            'throttle' => \App\Http\Middleware\ThrottleRequests::class,
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {


        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $apiResponse = function (int $statusCode, string $message, mixed $errors = null) {
            $payload = ['message' => $message];
            if ($errors !== null) {
                $payload['errors'] = $errors;
            }
            return response()->json([
                'statusCode' => $statusCode,
                'statusText' => 'failed',
                'data' => $payload,
            ], $statusCode);
        };

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                return $apiResponse(422, 'Validation failed.', $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($apiResponse) {
            return $apiResponse(401, 'Unauthenticated.', null);
        });

        // A missing record (e.g. firstOrFail / route-model binding) is a 404,
        // not a 500 — and never leak the model class name to the client.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                return $apiResponse(404, 'Resource not found.', null);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                return $apiResponse($e->getStatusCode(), $e->getMessage() ?: 'Resource not found.', null);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                // Only expose internals when debugging; production gets a clean message.
                $message = config('app.debug')
                    ? $e->getMessage() . ' [' . get_class($e) . ']'
                    : 'Something went wrong.';
                return $apiResponse(500, $message, null);
            }
        });

    })->create();
