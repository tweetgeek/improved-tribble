<?php

namespace App\Services\Webhooks\Contracts;

use App\Services\Payments\Data\PaymentEvent;
use App\Services\Webhooks\Exceptions\InvalidWebhookSignature;
use App\Services\Webhooks\Signatures\SignatureVerification;
use Illuminate\Http\Request;

/**
 * A payment gateway sending webhooks to POST /api/v1/webhooks/payment, identified by the
 * X-Payment-Provider header. Register implementations in config/webhooks.php; the registry
 * passes the provider's config entry as the "options" constructor argument.
 */
interface PaymentProvider
{
    /**
     * Authenticates the sender from the raw request, before the body is parsed.
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(Request $request): SignatureVerification;

    /**
     * Validation rules of the provider's payload format.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array;

    /**
     * Maps a payload that already passed rules() to the unified payment event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toPaymentEvent(string $provider, array $payload): PaymentEvent;
}
