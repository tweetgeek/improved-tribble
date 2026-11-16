<?php

namespace App\Services\Payments;

use App\Services\Payments\Data\Money;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentNotification;
use Illuminate\Support\Number;

/**
 * Builds the Polish notification for the sales team (contracts/notification-content.md):
 * amounts like "299,90 PLN", times in config('notifications.timezone'), "—" for missing values.
 */
final class PaymentNotificationFormatter
{
    private const MISSING = '—';

    public function format(PaymentEvent $event): PaymentNotification
    {
        $amount = $this->formatMoney($event->amount);
        $subject = ($event->livemode ? '' : '[TEST] ')
            ."Płatność: {$event->type} · zamówienie {$event->orderId} · {$amount}";

        $fields = [
            'Tryb' => $event->livemode ? 'produkcyjny' : 'TESTOWE',
            'Zdarzenie' => $this->describeType($event->type),
            'Numer zamówienia' => $event->orderId,
            'Kwota' => $amount,
            'Status transakcji' => $event->transactionStatus,
            'Klient' => trim(($event->customer?->firstName ?? '').' '.($event->customer?->lastName ?? '')),
            'Firma' => $event->customer?->company,
            'E-mail klienta' => $event->customer?->email,
            'Kraj' => $event->customer?->country,
            'Metoda płatności' => $this->describePaymentMethod($event),
            'Identyfikator transakcji' => $event->transactionId,
            'Referencja dostawcy' => $event->providerReference,
            'Dostawca' => $event->provider,
            'Sklep' => $event->storeId,
            'Kanał sprzedaży' => $event->salesChannel,
            'Czas zdarzenia' => $event->occurredAt
                ->setTimezone((string) config('notifications.timezone', 'UTC'))
                ->format('d.m.Y H:i T'),
            'Identyfikator zdarzenia' => $event->eventId,
        ];

        return new PaymentNotification(
            deliveryKey: $event->deliveryKey(),
            subject: $subject,
            isTest: ! $event->livemode,
            fields: array_map(
                fn (string $label, ?string $value): array => ['label' => $label, 'value' => blank($value) ? self::MISSING : $value],
                array_keys($fields),
                array_values($fields),
            ),
            event: $event,
        );
    }

    private function formatMoney(Money $money): string
    {
        return Number::format($money->minorUnits / 100, precision: 2, locale: 'pl').' '.$money->currency;
    }

    private function describeType(string $type): string
    {
        // Event types contain dots, so the label map is read whole instead of with dot notation.
        $label = ((array) config('notifications.event_labels', []))[$type] ?? null;

        return is_string($label) && $label !== '' ? "{$label} ({$type})" : $type;
    }

    private function describePaymentMethod(PaymentEvent $event): ?string
    {
        $type = $event->paymentMethod?->type;
        $issuer = $event->paymentMethod?->issuer;

        if (blank($type)) {
            return null;
        }

        return blank($issuer) ? $type : "{$type} ({$issuer})";
    }
}
