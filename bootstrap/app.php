<?php

use App\Http\ErrorResponse;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\RejectMalformedPayload;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            AssignCorrelationId::class,
            RejectMalformedPayload::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        $exceptions->render(
            fn (Throwable $exception, Request $request) => $exception instanceof HttpResponseException
                ? null
                : ErrorResponse::fromThrowable($exception),
        );

        $exceptions->stopIgnoring([ValidationException::class, HttpException::class]);
        $exceptions->dontReport([NotFoundHttpException::class, MethodNotAllowedHttpException::class]);

        $exceptions->context(function (Throwable $exception, array $context): array {
            if (! app()->bound('request') || ! request()->server->has('REQUEST_METHOD')) {
                return [];
            }

            return [
                'request' => [
                    'method' => request()->method(),
                    'path' => request()->path(),
                    'headers' => request()->headers->all(),
                    'body' => request()->all(),
                ],
            ];
        });
    })->create();
