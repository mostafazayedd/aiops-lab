<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', \App\Http\Middleware\TelemetryMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*')) {

                $category = 'UNKNOWN';

                if ($e instanceof ValidationException) {
                    $category = 'VALIDATION_ERROR';
                } elseif ($e instanceof QueryException) {
                    $category = 'DATABASE_ERROR';
                } else {
                    $message = strtolower($e->getMessage());
                    if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
                        $category = 'TIMEOUT_ERROR';
                    } elseif (method_exists($e, 'getStatusCode') && $e->getStatusCode() >= 500) {
                        $category = 'SYSTEM_ERROR';
                    }
                }

                $statusCode = 500;
                if ($e instanceof ValidationException) {
                    $statusCode = 422;
                } elseif (method_exists($e, 'getStatusCode')) {
                    $statusCode = $e->getStatusCode();
                }

                return response()->json([
                    'status'         => 'error',
                    'error_category' => $category,
                    'message'        => $e->getMessage(),
                ], $statusCode);
            }
        });

    })->create();