<?php

namespace App\Services\Webhooks\Providers;

use App\Services\Payments\Data\CustomerDetails;
use App\Services\Payments\Data\Money;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentMethodDetails;
use App\Services\Webhooks\Contracts\PaymentProvider;
use App\Services\Webhooks\Signatures\HmacSignatureVerifier;
use App\Services\Webhooks\Signatures\SignatureVerification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Gateway sending the format of payment_payload_example.json, signed in the
 * "X-Webhook-Signature" header.
 */
final class GenericGatewayProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    /**
     * @param  array{secret?: string|null, previous_secret?: string|null, tolerance?: int}  $options
     */
    public function __construct(
        private readonly HmacSignatureVerifier $verifier,
        private readonly array $options,
    ) {
    }

    public function verify(Request $request): SignatureVerification
    {
        return $this->verifier->verify(
            $request->header(self::SIGNATURE_HEADER),
            $request->getContent(),
            (string) ($this->options['secret'] ?? ''),
            $this->options['previous_secret'] ?? null,
            (int) ($this->options['tolerance'] ?? 300),
        );
    }

    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'max:100'],
            'event_id' => ['required', 'string', 'max:255'],
            'timestamp' => ['required', 'integer:strict', 'min:1'],
            'livemode' => ['sometimes', 'boolean:strict'],
            'data' => ['required', 'array'],
            'data.transaction.id' => ['required', 'string', 'max:255'],
            'data.transaction.provider_reference' => ['nullable', 'string', 'max:255'],
            'data.transaction.amount.value' => ['required', 'integer:strict', 'min:1'],
            'data.transaction.amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'data.transaction.status' => ['required', 'string', 'max:50'],
            'data.transaction.payment_method' => ['nullable', 'array'],
            'data.transaction.payment_method.type' => ['nullable', 'string', 'max:100'],
            'data.transaction.payment_method.issuer' => ['nullable', 'string', 'max:100'],
            'data.customer' => ['nullable', 'array'],
            'data.customer.id' => ['nullable', 'string', 'max:255'],
            'data.customer.email' => ['nullable', 'email'],
            'data.customer.billing_address.first_name' => ['nullable', 'string', 'max:255'],
            'data.customer.billing_address.last_name' => ['nullable', 'string', 'max:255'],
            'data.customer.billing_address.company' => ['nullable', 'string', 'max:255'],
            'data.customer.billing_address.country' => ['nullable', 'string', 'size:2'],
            'data.metadata.order_id' => ['required', 'string', 'max:255'],
            'data.metadata.store_id' => ['nullable', 'string', 'max:255'],
            'data.metadata.channel' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toPaymentEvent(string $provider, array $payload): PaymentEvent
    {
        $paymentMethod = Arr::get($payload, 'data.transaction.payment_method');
        $customer = Arr::get($payload, 'data.customer');

        return new PaymentEvent(
            provider: $provider,
            eventId: $payload['event_id'],
            type: $payload['event'],
            occurredAt: CarbonImmutable::createFromTimestamp($payload['timestamp'], 'UTC'),
            livemode: $payload['livemode'] ?? true,
            transactionId: Arr::get($payload, 'data.transaction.id'),
            amount: new Money(
                Arr::get($payload, 'data.transaction.amount.value'),
                Arr::get($payload, 'data.transaction.amount.currency'),
            ),
            transactionStatus: Arr::get($payload, 'data.transaction.status'),
            orderId: Arr::get($payload, 'data.metadata.order_id'),
            providerReference: Arr::get($payload, 'data.transaction.provider_reference'),
            paymentMethod: is_array($paymentMethod)
                ? new PaymentMethodDetails($paymentMethod['type'] ?? null, $paymentMethod['issuer'] ?? null)
                : null,
            customer: is_array($customer)
                ? new CustomerDetails(
                    id: $customer['id'] ?? null,
                    email: $customer['email'] ?? null,
                    firstName: Arr::get($customer, 'billing_address.first_name'),
                    lastName: Arr::get($customer, 'billing_address.last_name'),
                    company: Arr::get($customer, 'billing_address.company'),
                    country: Arr::get($customer, 'billing_address.country'),
                )
                : null,
            storeId: Arr::get($payload, 'data.metadata.store_id'),
            salesChannel: Arr::get($payload, 'data.metadata.channel'),
        );
    }
}
