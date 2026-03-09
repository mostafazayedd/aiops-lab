<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $category = $this->categorizeError($e, $request);

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
    }

    public function categorizeError(Throwable $e, Request $request): string
    {
        if ($e instanceof ValidationException) {
            return 'VALIDATION_ERROR';
        }

        if ($e instanceof QueryException) {
            return 'DATABASE_ERROR';
        }

        $latency = 0;
        if ($request->attributes->has('start_time')) {
            $latency = round((microtime(true) - $request->attributes->get('start_time')) * 1000);
        }

        if ($latency > 4000) {
            return 'TIMEOUT_ERROR';
        }

        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'TIMEOUT_ERROR';
        }

        if (method_exists($e, 'getStatusCode') && $e->getStatusCode() >= 500) {
            return 'SYSTEM_ERROR';
        }

        return 'UNKNOWN';
    }
}