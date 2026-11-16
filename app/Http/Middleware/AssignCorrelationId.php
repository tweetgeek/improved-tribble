<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    private const VALID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header(self::HEADER);

        $correlationId = is_string($provided) && preg_match(self::VALID_PATTERN, $provided) === 1
            ? $provided
            : (string) Str::uuid7();

        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
