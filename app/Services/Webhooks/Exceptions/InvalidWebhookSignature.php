<?php

namespace App\Services\Webhooks\Exceptions;

use App\Http\ErrorResponse;
use App\Services\Webhooks\WebhookRejectionLogger;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * The webhook signature is missing, expired or does not match. The precise reason is logged,
 * never returned to the sender.
 */
final class InvalidWebhookSignature extends Exception
{
    public const MISSING = 'missing_signature';

    public const EXPIRED = 'signature_expired';

    public const MISMATCH = 'invalid_signature';

    private function __construct(public readonly string $reason)
    {
        parent::__construct("Webhook signature verification failed: {$reason}.");
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public static function mismatch(): self
    {
        return new self(self::MISMATCH);
    }

    public function report(): void
    {
        WebhookRejectionLogger::log(Context::get('payment_provider'), $this->reason);
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make('invalid_signature', 'Webhook signature verification failed.', 401);
    }
}
