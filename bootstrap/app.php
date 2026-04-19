<?php

use Illuminate\Auth\AuthenticationException;
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
            if ($request->is('api/*')) {
                return $apiResponse(401, 'Unauthenticated.');
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                return $apiResponse($e->getStatusCode(), $e->getMessage() ?: 'HTTP error.');
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($apiResponse) {
            if ($request->is('api/*')) {
                $statusCode = 500;
                $message = app()->isProduction() ? 'Server error.' : $e->getMessage();
                return $apiResponse($statusCode, $message);
            }
        });

    })->create();
