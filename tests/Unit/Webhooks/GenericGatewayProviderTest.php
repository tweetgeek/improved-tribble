<?php

use App\Services\Payments\Data\PaymentEvent;
use App\Services\Webhooks\Providers\GenericGatewayProvider;
use App\Services\Webhooks\Signatures\HmacSignatureVerifier;

/**
 * @return array<string, mixed>
 */
function genericExamplePayload(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/payment_payload_example.json'), true)
        ?? throw new RuntimeException('tests/Fixtures/payment_payload_example.json is missing or invalid.');
}

function genericProvider(): GenericGatewayProvider
{
    return new GenericGatewayProvider(new HmacSignatureVerifier(), [
        'secret' => 'whsec_test_secret',
        'previous_secret' => null,
        'tolerance' => 300,
    ]);
}

it('maps the example payload to the unified payment event', function () {
    $event = genericProvider()->toPaymentEvent('generic', genericExamplePayload());

    expect($event)->toBeInstanceOf(PaymentEvent::class)
        ->and($event->provider)->toBe('generic')
        ->and($event->eventId)->toBe('evt_9f8d7c6b5a4321')
        ->and($event->type)->toBe('charge.successful')
        ->and($event->occurredAt->toIso8601String())->toBe('2024-06-10T14:20:00+00:00')
        ->and($event->livemode)->toBeTrue()
        ->and($event->transactionId)->toBe('txn_8a7b6c5d4e3f')
        ->and($event->providerReference)->toBe('PAYU-9876543210')
        ->and($event->amount->minorUnits)->toBe(29990)
        ->and($event->amount->currency)->toBe('PLN')
        ->and($event->transactionStatus)->toBe('COMPLETED')
        ->and($event->paymentMethod?->type)->toBe('blik')
        ->and($event->paymentMethod?->issuer)->toBe('mBank')
        ->and($event->customer?->id)->toBe('cus_11223344')
        ->and($event->customer?->email)->toBe('jan.kowalski@example.com')
        ->and($event->customer?->firstName)->toBe('Jan')
        ->and($event->customer?->lastName)->toBe('Kowalski')
        ->and($event->customer?->company)->toBe('Kowalski Dev Ltd')
        ->and($event->customer?->country)->toBe('PL')
        ->and($event->orderId)->toBe('ORD-2024-8839')
        ->and($event->storeId)->toBe('store_main_pl')
        ->and($event->salesChannel)->toBe('mobile_app')
        ->and($event->deliveryKey())->toBe('generic:evt_9f8d7c6b5a4321');
});

it('treats a missing livemode flag as a live event', function () {
    $payload = genericExamplePayload();
    unset($payload['livemode']);

    expect(genericProvider()->toPaymentEvent('generic', $payload)->livemode)->toBeTrue();
});

it('maps missing optional sections to null', function () {
    $payload = genericExamplePayload();
    unset(
        $payload['data']['customer'],
        $payload['data']['transaction']['payment_method'],
        $payload['data']['transaction']['provider_reference'],
        $payload['data']['metadata']['store_id'],
        $payload['data']['metadata']['channel'],
    );

    $event = genericProvider()->toPaymentEvent('generic', $payload);

    expect($event->customer)->toBeNull()
        ->and($event->paymentMethod)->toBeNull()
        ->and($event->providerReference)->toBeNull()
        ->and($event->storeId)->toBeNull()
        ->and($event->salesChannel)->toBeNull();
});

it('declares exactly the validation rules from the data model', function () {
    expect(genericProvider()->rules())->toBe([
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
    ]);
});
