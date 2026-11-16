<?php

use App\Enums\DeliveryStatus;
use App\Events\PaymentEventReceived;
use App\Jobs\Notifications\SendMailtrapNotification;
use App\Jobs\Notifications\SendSalesTeamNotification;
use App\Listeners\QueuePaymentNotifications;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Monolog\LogRecord;
use Tests\Support\LogCapture;
use Tests\Support\Notifications\PaymentEvents;
use Tests\Support\Notifications\RecordingChannel;
use Tests\Support\Notifications\SendRecordingNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingChannel::reset();
    config(['notifications.channels.recording' => ['enabled' => true, 'job' => SendRecordingNotification::class]]);
});

afterEach(function () {
    RecordingChannel::reset();
});

it('fans out to a channel added only through configuration', function () {
    Queue::fake();

    app(QueuePaymentNotifications::class)->handle(new PaymentEventReceived(PaymentEvents::example()));

    Queue::assertPushed(SendMailtrapNotification::class);
    Queue::assertPushed(SendSalesTeamNotification::class);
    Queue::assertPushed(SendRecordingNotification::class);
});

it('gives the new channel the same retry policy, idempotency and logging as existing channels', function () {
    $job = new SendRecordingNotification(PaymentEvents::example());

    expect($job->tries())->toBe(4)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and($job->timeout)->toBe(30);

    app()->call([$job->withFakeQueueInteractions(), 'handle']);

    expect(RecordingChannel::$received)->toHaveCount(1)
        ->and(NotificationDelivery::where('channel', 'recording')->sole()->status)->toBe(DeliveryStatus::Delivered);

    $logs = LogCapture::start();
    RecordingChannel::$failWith = new RuntimeException('recording down');
    $retry = (new SendRecordingNotification(PaymentEvents::example(['eventId' => 'evt_second'])))->withFakeQueueInteractions();

    expect(fn () => app()->call([$retry, 'handle']))->toThrow(RuntimeException::class);

    $failed = array_values(array_filter($logs->getRecords(), fn (LogRecord $r): bool => $r->message === 'notification.attempt_failed'));

    expect($failed)->toHaveCount(1)->and($failed[0]->context['channel'])->toBe('recording');
});
