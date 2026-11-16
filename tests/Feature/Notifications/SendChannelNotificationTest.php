<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;
use Tests\Support\Notifications\PaymentEvents;
use Tests\Support\Notifications\RecordingChannel;
use Tests\Support\Notifications\SendRecordingNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingChannel::reset();
    $this->logs = LogCapture::start();
});

afterEach(function () {
    RecordingChannel::reset();
});

/**
 * @return list<LogRecord>
 */
function channelJobLogs(TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

function recordingJob(array $overrides = []): SendRecordingNotification
{
    return (new SendRecordingNotification(PaymentEvents::example($overrides)))->withFakeQueueInteractions();
}

it('retries 4 times after 1, 5 and 15 minutes and times out before the queue gives up', function () {
    $job = new SendRecordingNotification(PaymentEvents::example());

    expect($job->tries())->toBe(4)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and($job->timeout)->toBe(30)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('names the queued job after its channel', function () {
    expect((new SendRecordingNotification(PaymentEvents::example()))->displayName())
        ->toBe(SendRecordingNotification::class.':recording');
});

it('delivers the formatted notification to its channel', function () {
    $job = recordingJob();

    app()->call([$job, 'handle']);

    $job->assertNotFailed();

    expect(RecordingChannel::$received)->toHaveCount(1)
        ->and(RecordingChannel::$received[0]->deliveryKey)->toBe('generic:evt_9f8d7c6b5a4321')
        ->and(RecordingChannel::$received[0]->subject)->toContain('299,90 PLN');

    $delivered = channelJobLogs($this->logs, 'notification.delivered');

    expect($delivered)->toHaveCount(1)
        ->and($delivered[0]->context)->toMatchArray([
            'channel' => 'recording',
            'provider' => 'generic',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'attempt' => 1,
        ]);
});

it('logs the move to dead-letter with the channel, notification and exception', function () {
    (new SendRecordingNotification(PaymentEvents::example()))->failed(new RuntimeException('channel down'));

    $deadLettered = channelJobLogs($this->logs, 'notification.dead_lettered');

    expect($deadLettered)->toHaveCount(1)
        ->and($deadLettered[0]->level)->toBe(Level::Error)
        ->and($deadLettered[0]->context['channel'])->toBe('recording')
        ->and($deadLettered[0]->context['provider'])->toBe('generic')
        ->and($deadLettered[0]->context['event_id'])->toBe('evt_9f8d7c6b5a4321')
        ->and($deadLettered[0]->context['notification']['subject'])->toContain('ORD-2024-8839')
        ->and($deadLettered[0]->context['exception'])->toBeInstanceOf(RuntimeException::class);
});

function deliveryRow(): ?App\Models\NotificationDelivery
{
    return App\Models\NotificationDelivery::query()
        ->where(['provider' => 'generic', 'event_id' => 'evt_9f8d7c6b5a4321', 'channel' => 'recording'])
        ->first();
}

it('records a successful delivery', function () {
    app()->call([recordingJob(), 'handle']);

    expect(deliveryRow()?->status)->toBe(App\Enums\DeliveryStatus::Delivered)
        ->and(deliveryRow()?->delivered_at)->not->toBeNull()
        ->and(deliveryRow()?->attempts)->toBe(1);
});

it('logs a transient failure, keeps the delivery pending and rethrows to retry', function (Throwable $failure) {
    RecordingChannel::$failWith = $failure;
    $job = recordingJob();

    expect(fn () => app()->call([$job, 'handle']))->toThrow($failure::class);

    $failed = channelJobLogs($this->logs, 'notification.attempt_failed');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->level)->toBe(Level::Error)
        ->and($failed[0]->context)->toMatchArray([
            'channel' => 'recording',
            'provider' => 'generic',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'attempt' => 1,
            'max_attempts' => 4,
            'transient' => true,
        ])
        ->and($failed[0]->context['notification']['delivery_key'])->toBe('generic:evt_9f8d7c6b5a4321')
        ->and($failed[0]->context['exception'])->toBe($failure);

    expect(deliveryRow()?->status)->toBe(App\Enums\DeliveryStatus::Pending)
        ->and(deliveryRow()?->attempts)->toBe(1)
        ->and(deliveryRow()?->last_error)->toContain('connection refused');

    $job->assertNotFailed();
})->with([
    'transient channel error' => fn () => new App\Services\Notifications\Channels\Exceptions\TransientChannelException('connection refused'),
    'unknown error' => fn () => new RuntimeException('connection refused'),
]);

it('fails a permanent error at once without further attempts', function () {
    RecordingChannel::$failWith = new App\Services\Notifications\Channels\Exceptions\PermanentChannelException('rejected as invalid');
    $job = recordingJob();

    app()->call([$job, 'handle']);

    $job->assertFailedWith(App\Services\Notifications\Channels\Exceptions\PermanentChannelException::class);

    expect(channelJobLogs($this->logs, 'notification.attempt_failed')[0]->context['transient'])->toBeFalse()
        ->and(deliveryRow()?->last_error)->toContain('rejected as invalid');
});

it('never sends an already delivered notification again', function () {
    App\Models\NotificationDelivery::factory()->delivered()->create([
        'provider' => 'generic',
        'event_id' => 'evt_9f8d7c6b5a4321',
        'channel' => 'recording',
    ]);
    $job = recordingJob();

    app()->call([$job, 'handle']);

    $job->assertNotFailed();

    expect(RecordingChannel::$received)->toBeEmpty()
        ->and(channelJobLogs($this->logs, 'notification.already_delivered'))->toHaveCount(1)
        ->and(channelJobLogs($this->logs, 'notification.delivered'))->toBeEmpty();
});

it('marks the delivery as failed when the job moves to dead-letter', function () {
    (new SendRecordingNotification(PaymentEvents::example()))->failed(new RuntimeException('gave up'));

    expect(deliveryRow()?->status)->toBe(App\Enums\DeliveryStatus::Failed)
        ->and(deliveryRow()?->failed_at)->not->toBeNull()
        ->and(deliveryRow()?->last_error)->toContain('gave up');
});

it('delivers a dead-lettered notification on manual retry', function () {
    RecordingChannel::$failWith = new RuntimeException('down');
    expect(fn () => app()->call([recordingJob(), 'handle']))->toThrow(RuntimeException::class);
    (new SendRecordingNotification(PaymentEvents::example()))->failed(new RuntimeException('down'));

    RecordingChannel::$failWith = null;
    app()->call([recordingJob(), 'handle']);

    expect(deliveryRow()?->status)->toBe(App\Enums\DeliveryStatus::Delivered)
        ->and(deliveryRow()?->attempts)->toBe(2)
        ->and(RecordingChannel::$received)->toHaveCount(1);
});
