<?php

namespace App\Services\Webhooks\Exceptions;

use App\Http\ErrorResponse;
use App\Services\Webhooks\WebhookRejectionLogger;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * The X-Payment-Provider header is missing, malformed or names an unregistered provider.
 * Without a provider the sender cannot be authenticated, hence 401 (research R1).
 */
final class UnknownPaymentProvider extends Exception
{
    public function __construct(public readonly ?string $providerId)
    {
        parent::__construct('Unknown payment provider.');
    }

    public function report(): void
    {
        WebhookRejectionLogger::log($this->providerId, 'unknown_provider');
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make('unknown_provider', 'Unknown payment provider.', 401);
    }
}
