<?php

namespace App\Services\Payments\Data;

/**
 * The notification content shared by every channel, built once by PaymentNotificationFormatter.
 */
final readonly class PaymentNotification
{
    /**
     * @param  list<array{label: string, value: string}>  $fields
     */
    public function __construct(
        public string $deliveryKey,
        public string $subject,
        public bool $isTest,
        public array $fields,
        public PaymentEvent $event,
    ) {
    }

    /**
     * @return array{delivery_key: string, subject: string, is_test: bool, fields: list<array{label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'delivery_key' => $this->deliveryKey,
            'subject' => $this->subject,
            'is_test' => $this->isTest,
            'fields' => $this->fields,
        ];
    }
}
