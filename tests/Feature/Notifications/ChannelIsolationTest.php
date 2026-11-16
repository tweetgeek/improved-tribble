<?php

use App\Enums\DeliveryStatus;
use App\Events\PaymentEventReceived;
use App\Jobs\Notifications\SendMailtrapNotification;
use App\Jobs\Notifications\SendSalesTeamNotification;
use App\Listeners\QueuePaymentNotifications;
use App\Mail\PaymentEventMail;
use App\Models\NotificationDelivery;
use App\Services\Notifications\Channels\Exceptions\TransientChannelException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Notifications\PaymentEvents;

uses(RefreshDatabase::class);

it('delivers to Mailtrap even when SalesTeam is failing and runs first', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'transient']);
    Queue::fake();
    Mail::fake();

    app(QueuePaymentNotifications::class)->handle(new PaymentEventReceived(PaymentEvents::example()));

    $salesTeam = Queue::pushed(SendSalesTeamNotification::class)->sole()->withFakeQueueInteractions();
    $mailtrap = Queue::pushed(SendMailtrapNotification::class)->sole()->withFakeQueueInteractions();

    expect(fn () => app()->call([$salesTeam, 'handle']))->toThrow(TransientChannelException::class);
    app()->call([$mailtrap, 'handle']);

    Mail::assertSent(PaymentEventMail::class, 1);
    $mailtrap->assertNotFailed();

    expect(NotificationDelivery::where('channel', 'mailtrap')->sole()->status)->toBe(DeliveryStatus::Delivered)
        ->and(NotificationDelivery::where('channel', 'salesteam')->sole()->status)->toBe(DeliveryStatus::Pending);
});
