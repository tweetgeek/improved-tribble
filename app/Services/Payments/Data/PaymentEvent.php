<?php

namespace App\Services\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * Provider-independent payment event. Everything after the provider layer (deduplication,
 * notifications, channels) works only with this model.
 */
final readonly class PaymentEvent
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $type,
        public CarbonImmutable $occurredAt,
        public bool $livemode,
        public string $transactionId,
        public Money $amount,
        public string $transactionStatus,
        public string $orderId,
        public ?string $providerReference = null,
        public ?PaymentMethodDetails $paymentMethod = null,
        public ?CustomerDetails $customer = null,
        public ?string $storeId = null,
        public ?string $salesChannel = null,
    ) {
    }

    /**
     * Stable key of the event across providers, used for deduplication of deliveries.
     */
    public function deliveryKey(): string
    {
        return "{$this->provider}:{$this->eventId}";
    }
}
