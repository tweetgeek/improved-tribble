<?php

use App\Services\Payments\Data\Money;
use App\Services\Payments\Data\PaymentMethodDetails;
use App\Services\Payments\Data\PaymentNotification;
use App\Services\Payments\PaymentNotificationFormatter;
use Illuminate\Support\Number;
use Tests\Support\Notifications\PaymentEvents;
use Tests\TestCase;

uses(TestCase::class);

function formatExample(array $overrides = []): PaymentNotification
{
    return app(PaymentNotificationFormatter::class)->format(PaymentEvents::example($overrides));
}

/**
 * @return array<string, string>
 */
function fieldMap(PaymentNotification $notification): array
{
    return array_column($notification->fields, 'value', 'label');
}

it('formats the example payment as the Polish notification from the contract', function () {
    $notification = formatExample();

    expect($notification->subject)->toBe('Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN')
        ->and($notification->isTest)->toBeFalse()
        ->and($notification->deliveryKey)->toBe('generic:evt_9f8d7c6b5a4321')
        ->and($notification->fields)->toBe([
            ['label' => 'Tryb', 'value' => 'produkcyjny'],
            ['label' => 'Zdarzenie', 'value' => 'Płatność zakończona sukcesem (charge.successful)'],
            ['label' => 'Numer zamówienia', 'value' => 'ORD-2024-8839'],
            ['label' => 'Kwota', 'value' => '299,90 PLN'],
            ['label' => 'Status transakcji', 'value' => 'COMPLETED'],
            ['label' => 'Klient', 'value' => 'Jan Kowalski'],
            ['label' => 'Firma', 'value' => 'Kowalski Dev Ltd'],
            ['label' => 'E-mail klienta', 'value' => 'jan.kowalski@example.com'],
            ['label' => 'Kraj', 'value' => 'PL'],
            ['label' => 'Metoda płatności', 'value' => 'blik (mBank)'],
            ['label' => 'Identyfikator transakcji', 'value' => 'txn_8a7b6c5d4e3f'],
            ['label' => 'Referencja dostawcy', 'value' => 'PAYU-9876543210'],
            ['label' => 'Dostawca', 'value' => 'generic'],
            ['label' => 'Sklep', 'value' => 'store_main_pl'],
            ['label' => 'Kanał sprzedaży', 'value' => 'mobile_app'],
            ['label' => 'Czas zdarzenia', 'value' => '10.06.2024 16:20 CEST'],
            ['label' => 'Identyfikator zdarzenia', 'value' => 'evt_9f8d7c6b5a4321'],
        ]);
});

it('formats large amounts with Polish thousands separators', function () {
    $notification = formatExample(['amount' => new Money(1234500, 'EUR')]);

    expect(fieldMap($notification)['Kwota'])->toBe(Number::format(12345, precision: 2, locale: 'pl').' EUR')
        ->and(fieldMap($notification)['Kwota'])->toStartWith('12')->toEndWith('345,00 EUR');
});

it('marks test events in the subject and the first field', function () {
    $notification = formatExample(['livemode' => false]);

    expect($notification->isTest)->toBeTrue()
        ->and($notification->subject)->toBe('[TEST] Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN')
        ->and($notification->fields[0])->toBe(['label' => 'Tryb', 'value' => 'TESTOWE']);
});

it('shows a dash for missing optional values and keeps the field', function () {
    $notification = formatExample([
        'customer' => null,
        'paymentMethod' => new PaymentMethodDetails('card', null),
        'providerReference' => null,
        'storeId' => null,
        'salesChannel' => null,
    ]);

    expect(fieldMap($notification))->toMatchArray([
        'Klient' => '—',
        'Firma' => '—',
        'E-mail klienta' => '—',
        'Kraj' => '—',
        'Metoda płatności' => 'card',
        'Referencja dostawcy' => '—',
        'Sklep' => '—',
        'Kanał sprzedaży' => '—',
    ])->and($notification->fields)->toHaveCount(17);
});

it('shows the raw type when the event type has no Polish label', function () {
    $notification = formatExample(['type' => 'charge.disputed']);

    expect(fieldMap($notification)['Zdarzenie'])->toBe('charge.disputed')
        ->and($notification->subject)->toStartWith('Płatność: charge.disputed');
});

it('converts the event time to the configured time zone', function () {
    config(['notifications.timezone' => 'UTC']);

    expect(fieldMap(formatExample())['Czas zdarzenia'])->toBe('10.06.2024 14:20 UTC');
});

it('serializes the notification for logs', function () {
    expect(formatExample()->toArray())->toHaveKeys(['delivery_key', 'subject', 'is_test', 'fields'])
        ->and(formatExample()->toArray()['delivery_key'])->toBe('generic:evt_9f8d7c6b5a4321');
});
