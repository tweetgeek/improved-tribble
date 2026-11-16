<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\Log;

/**
 * Writes the single "webhook.rejected" error entry for every rejected payment webhook
 * (constitution, principle IV). Secrets in headers and body are masked by the log processor.
 */
final class WebhookRejectionLogger
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function log(?string $provider, string $reason, array $extra = []): void
    {
        $request = request();

        Log::error('webhook.rejected', [
            'provider' => $provider,
            'reason' => $reason,
            'method' => $request->method(),
            'path' => $request->path(),
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            ...$extra,
        ]);
    }
}
