<?php

use App\Events\PaymentEventReceived;
use App\Jobs\Notifications\SendMailtrapNotification;
use App\Jobs\Notifications\SendSalesTeamNotification;
use App\Listeners\QueuePaymentNotifications;
use App\Services\Payments\Data\PaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;
use Tests\Support\Notifications\PaymentEvents;

beforeEach(function () {
    Queue::fake();
    $this->logs = LogCapture::start();
});

function runNotificationListener(PaymentEvent $event): void
{
    app(QueuePaymentNotifications::class)->handle(new PaymentEventReceived($event));
}

/**
 * @return list<LogRecord>
 */
function listenerLogs(TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

it('listens to accepted payment events on the queue', function () {
    Event::fake();

    Event::assertListening(PaymentEventReceived::class, QueuePaymentNotifications::class);
    expect(QueuePaymentNotifications::class)->toImplement(ShouldQueue::class);
});

it('queues one job per enabled channel with the same payment event', function () {
    $event = PaymentEvents::example();

    runNotificationListener($event);

    Queue::assertPushed(SendMailtrapNotification::class, fn (SendMailtrapNotification $job): bool => $job->paymentEvent === $event);
    Queue::assertPushed(SendSalesTeamNotification::class, fn (SendSalesTeamNotification $job): bool => $job->paymentEvent === $event);
    Queue::assertCount(2);

    $queued = listenerLogs($this->logs, 'notifications.queued');

    expect($queued)->toHaveCount(1)
        ->and($queued[0]->context)->toMatchArray([
            'provider' => 'generic',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'channels' => ['mailtrap', 'salesteam'],
        ]);
});

it('skips a disabled channel', function () {
    config(['notifications.channels.salesteam.enabled' => false]);

    runNotificationListener(PaymentEvents::example());

    Queue::assertPushed(SendMailtrapNotification::class);
    Queue::assertNotPushed(SendSalesTeamNotification::class);
});

it('skips test events when test notifications are disabled', function () {
    config(['notifications.notify_test_events' => false]);

    runNotificationListener(PaymentEvents::example(['livemode' => false]));

    Queue::assertNothingPushed();

    $skipped = listenerLogs($this->logs, 'notifications.test_event_skipped');

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]->level)->toBe(Level::Info)
        ->and($skipped[0]->context)->toMatchArray(['provider' => 'generic', 'event_id' => 'evt_9f8d7c6b5a4321']);
});

it('notifies about test events when test notifications are enabled', function () {
    config(['notifications.notify_test_events' => true]);

    runNotificationListener(PaymentEvents::example(['livemode' => false]));

    Queue::assertCount(2);
});

it('warns when no channel is enabled', function () {
    config([
        'notifications.channels.mailtrap.enabled' => false,
        'notifications.channels.salesteam.enabled' => false,
    ]);

    runNotificationListener(PaymentEvents::example());

    Queue::assertNothingPushed();

    $warnings = listenerLogs($this->logs, 'notifications.no_channels_enabled');

    expect($warnings)->toHaveCount(1)->and($warnings[0]->level)->toBe(Level::Warning);
});

it('fails loudly when a channel is registered with a job that is not a channel job', function () {
    config(['notifications.channels.broken' => ['enabled' => true, 'job' => stdClass::class]]);

    expect(fn () => runNotificationListener(PaymentEvents::example()))->toThrow(LogicException::class);

    $errors = listenerLogs($this->logs, 'notifications.invalid_channel_config');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->level)->toBe(Level::Error)
        ->and($errors[0]->context)->toMatchArray(['channel' => 'broken', 'job' => stdClass::class]);
});

it('logs its own failure with the event and the exception', function () {
    app(QueuePaymentNotifications::class)->failed(
        new PaymentEventReceived(PaymentEvents::example()),
        new RuntimeException('redis gone'),
    );

    $errors = LogCapture::errors($this->logs);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->message)->toBe('notifications.queueing_failed')
        ->and($errors[0]->context['event_id'])->toBe('evt_9f8d7c6b5a4321')
        ->and($errors[0]->context['exception'])->toBeInstanceOf(RuntimeException::class);
});

it('uses the default retry policy of the foundation', function () {
    $listener = app(QueuePaymentNotifications::class);

    expect($listener->tries())->toBe(config('queue.retry_policy.tries'))
        ->and($listener->backoff())->toBe(config('queue.retry_policy.backoff'))
        ->and($listener->timeout)->toBe(config('queue.retry_policy.timeout'));
});
