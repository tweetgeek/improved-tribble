<?php

namespace Tests\Support\Notifications;

use App\Services\Payments\Data\CustomerDetails;
use App\Services\Payments\Data\Money;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentMethodDetails;
use Carbon\CarbonImmutable;

/**
 * PaymentEvent instances for notification tests, matching payment_payload_example.json.
 */
final class PaymentEvents
{
    /**
     * @param  array<string, mixed>  $overrides  Named PaymentEvent constructor arguments.
     */
    public static function example(array $overrides = []): PaymentEvent
    {
        return new PaymentEvent(...array_replace([
            'provider' => 'generic',
            'eventId' => 'evt_9f8d7c6b5a4321',
            'type' => 'charge.successful',
            'occurredAt' => CarbonImmutable::createFromTimestamp(1718029200, 'UTC'),
            'livemode' => true,
            'transactionId' => 'txn_8a7b6c5d4e3f',
            'amount' => new Money(29990, 'PLN'),
            'transactionStatus' => 'COMPLETED',
            'orderId' => 'ORD-2024-8839',
            'providerReference' => 'PAYU-9876543210',
            'paymentMethod' => new PaymentMethodDetails('blik', 'mBank'),
            'customer' => new CustomerDetails(
                id: 'cus_11223344',
                email: 'jan.kowalski@example.com',
                firstName: 'Jan',
                lastName: 'Kowalski',
                company: 'Kowalski Dev Ltd',
                country: 'PL',
            ),
            'storeId' => 'store_main_pl',
            'salesChannel' => 'mobile_app',
        ], $overrides));
    }
}
