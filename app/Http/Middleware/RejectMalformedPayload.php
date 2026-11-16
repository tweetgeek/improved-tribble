<?php

namespace App\Http\Middleware;

use App\Http\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RejectMalformedPayload
{
    private const MAX_BODY_BYTES = 1048576;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if (strlen($content) > self::MAX_BODY_BYTES) {
            $this->logRejection($request, $content);

            return ErrorResponse::make('payload_too_large', 'The request body is too large.', 413);
        }

        if ($request->isJson() && $content !== '' && ! json_validate($content)) {
            $this->logRejection($request, $content);

            return ErrorResponse::make('malformed_payload', 'Request body is not valid JSON.', 400);
        }

        return $next($request);
    }

    private function logRejection(Request $request, string $content): void
    {
        Log::error('Rejected malformed request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'headers' => $request->headers->all(),
            'body' => $content,
        ]);
    }
}
