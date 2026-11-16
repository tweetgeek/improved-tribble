<?php

use App\Services\Notifications\Channels\SalesTeamChannel;
use App\Services\Payments\PaymentNotificationFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;
use Tests\Support\Notifications\PaymentEvents;

beforeEach(function () {
    config(['notifications.channels.salesteam.mock' => ['mode' => 'success', 'fail_first' => 2]]);
    $this->logs = LogCapture::start();
    $this->notification = app(PaymentNotificationFormatter::class)->format(PaymentEvents::example());
});

/**
 * @return list<LogRecord>
 */
function salesTeamLogs(Monolog\Handler\TestHandler $handler): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === 'salesteam.mock.received'));
}

it('records the notification in the log in success mode', function () {
    app(SalesTeamChannel::class)->send($this->notification);

    $received = salesTeamLogs($this->logs);

    expect(app(SalesTeamChannel::class)->name())->toBe('salesteam')
        ->and($received)->toHaveCount(1)
        ->and($received[0]->level)->toBe(Level::Info)
        ->and($received[0]->context['mode'])->toBe('success')
        ->and($received[0]->context['notification'])->toBe([
            'delivery_key' => 'generic:evt_9f8d7c6b5a4321',
            'subject' => 'Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN',
            'is_test' => false,
            'fields' => $this->notification->fields,
        ]);
});

it('fails every attempt with a transient error in transient mode', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'transient']);

    expect(fn () => app(SalesTeamChannel::class)->send($this->notification))
        ->toThrow(App\Services\Notifications\Channels\Exceptions\TransientChannelException::class)
        ->and(fn () => app(SalesTeamChannel::class)->send($this->notification))
        ->toThrow(App\Services\Notifications\Channels\Exceptions\TransientChannelException::class);

    expect(salesTeamLogs($this->logs))->toHaveCount(2)
        ->and(salesTeamLogs($this->logs)[0]->context['mode'])->toBe('transient');
});

it('fails with a permanent error in permanent mode', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'permanent']);

    expect(fn () => app(SalesTeamChannel::class)->send($this->notification))
        ->toThrow(App\Services\Notifications\Channels\Exceptions\PermanentChannelException::class);
});

it('fails the first N attempts of each notification and then succeeds in fail_first_n mode', function () {
    config(['notifications.channels.salesteam.mock' => ['mode' => 'fail_first_n', 'fail_first' => 2]]);
    $channel = app(SalesTeamChannel::class);
    $transient = App\Services\Notifications\Channels\Exceptions\TransientChannelException::class;

    expect(fn () => $channel->send($this->notification))->toThrow($transient)
        ->and(fn () => $channel->send($this->notification))->toThrow($transient);

    $channel->send($this->notification);

    $other = app(App\Services\Payments\PaymentNotificationFormatter::class)
        ->format(PaymentEvents::example(['eventId' => 'evt_other']));

    expect(fn () => $channel->send($other))->toThrow($transient)
        ->and(salesTeamLogs($this->logs))->toHaveCount(4);
});

it('rejects an unknown mock mode', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'chaos']);

    expect(fn () => app(SalesTeamChannel::class)->send($this->notification))->toThrow(LogicException::class);
});
