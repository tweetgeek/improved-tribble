<?php

namespace App\Services\Webhooks\Exceptions;

use App\Http\ErrorResponse;
use App\Services\Webhooks\WebhookRejectionLogger;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * An authenticated webhook whose body is empty or not a JSON object. Same response as the
 * global RejectMalformedPayload middleware, which skips webhook paths (research R2).
 */
final class MalformedWebhookPayload extends Exception
{
    public function __construct()
    {
        parent::__construct('Request body is not valid JSON.');
    }

    public function report(): void
    {
        WebhookRejectionLogger::log(Context::get('payment_provider'), 'malformed_payload');
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make('malformed_payload', 'Request body is not valid JSON.', 400);
    }
}
