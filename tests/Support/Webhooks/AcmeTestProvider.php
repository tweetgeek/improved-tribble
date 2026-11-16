<?php

namespace Tests\Support\Webhooks;

use App\Services\Payments\Data\PaymentEvent;
use App\Services\Webhooks\Contracts\PaymentProvider;
use App\Services\Webhooks\Providers\GenericGatewayProvider;
use App\Services\Webhooks\Signatures\HmacSignatureVerifier;
use App\Services\Webhooks\Signatures\SignatureVerification;
use Illuminate\Http\Request;

/**
 * A second provider registered only through config() in tests. It reuses the generic format
 * and signature scheme, which keeps the tests about routing and isolation between providers.
 */
final class AcmeTestProvider implements PaymentProvider
{
    private readonly GenericGatewayProvider $format;

    /**
     * @param  array{secret?: string|null, previous_secret?: string|null, tolerance?: int}  $options
     */
    public function __construct(HmacSignatureVerifier $verifier, array $options)
    {
        $this->format = new GenericGatewayProvider($verifier, $options);
    }

    public function verify(Request $request): SignatureVerification
    {
        return $this->format->verify($request);
    }

    public function rules(): array
    {
        return $this->format->rules();
    }

    public function toPaymentEvent(string $provider, array $payload): PaymentEvent
    {
        return $this->format->toPaymentEvent($provider, $payload);
    }
}
