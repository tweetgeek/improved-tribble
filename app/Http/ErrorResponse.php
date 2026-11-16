<?php

namespace App\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single JSON error format of the application (see contracts/http-errors.md).
 */
final class ErrorResponse
{
    /**
     * @param  array<string, list<string>>|null  $details
     */
    public static function make(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'correlation_id' => (string) Context::get('correlation_id', ''),
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    public static function fromThrowable(Throwable $exception): JsonResponse
    {
        $status = match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        [$code, $message] = match ($status) {
            401 => ['unauthenticated', 'Authentication is required.'],
            403 => ['forbidden', 'This action is forbidden.'],
            404 => ['not_found', 'The requested resource was not found.'],
            405 => ['method_not_allowed', 'The HTTP method is not allowed for this endpoint.'],
            413 => ['payload_too_large', 'The request body is too large.'],
            419 => ['page_expired', 'The request token has expired.'],
            422 => ['validation_failed', 'The given data was invalid.'],
            429 => ['too_many_requests', 'Too many requests.'],
            503 => ['service_unavailable', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['internal_error', 'Internal server error.']
                : ['client_error', 'The request could not be processed.'],
        };

        if ($status === 500 && config('app.debug')) {
            $message = $exception->getMessage();
        }

        return self::make(
            $code,
            $message,
            $status,
            $exception instanceof ValidationException ? $exception->errors() : null,
        );
    }
}
