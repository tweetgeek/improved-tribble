<?php

namespace App\Services\Webhooks\Exceptions;

use App\Http\ErrorResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * A registered provider lacks required configuration (e.g. its signing secret). This is a
 * server-side error: 500 makes the provider retry once the configuration is fixed.
 */
final class PaymentProviderMisconfigured extends Exception
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly string $providerId, public readonly array $missing)
    {
        parent::__construct("Payment provider [{$providerId}] is misconfigured.");
    }

    public function report(): void
    {
        Log::error('webhook.provider_misconfigured', [
            'provider' => $this->providerId,
            'missing' => $this->missing,
        ]);
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make('internal_error', 'Internal server error.', 500);
    }
}
