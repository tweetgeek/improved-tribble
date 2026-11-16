<?php

namespace App\Services\Webhooks\Exceptions;

use App\Services\Webhooks\WebhookRejectionLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

/**
 * An authenticated webhook that breaks the provider's validation rules. Rendered by the
 * common error format as 422 "validation_failed" with details; reported once as
 * "webhook.rejected" instead of the generic validation log entry.
 */
final class InvalidWebhookPayload extends ValidationException
{
    public function report(): void
    {
        WebhookRejectionLogger::log(Context::get('payment_provider'), 'validation_failed', [
            'errors' => $this->errors(),
        ]);
    }
}
