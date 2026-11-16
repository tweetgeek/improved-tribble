<?php

namespace App\Listeners;

use App\Events\PaymentEventReceived;
use App\Jobs\Concerns\UsesDefaultRetryPolicy;
use App\Services\Notifications\NotificationChannelRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans an accepted payment event out to one queued job per enabled channel. Re-running it after
 * a partial fan-out is safe: channel jobs never deliver the same event twice.
 */
class QueuePaymentNotifications implements ShouldQueue
{
    use UsesDefaultRetryPolicy;

    public function __construct(private readonly NotificationChannelRegistry $channels)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(PaymentEventReceived $event): void
    {
        $paymentEvent = $event->paymentEvent;
        $context = ['provider' => $paymentEvent->provider, 'event_id' => $paymentEvent->eventId];

        if (! $paymentEvent->livemode && ! config('notifications.notify_test_events')) {
            Log::info('notifications.test_event_skipped', $context);

            return;
        }

        $jobs = $this->channels->enabled();

        if ($jobs === []) {
            Log::warning('notifications.no_channels_enabled', $context);

            return;
        }

        foreach ($jobs as $job) {
            $job::dispatch($paymentEvent);
        }

        Log::info('notifications.queued', [...$context, 'channels' => array_keys($jobs)]);
    }

    public function failed(PaymentEventReceived $event, Throwable $exception): void
    {
        Log::error('notifications.queueing_failed', [
            'provider' => $event->paymentEvent->provider,
            'event_id' => $event->paymentEvent->eventId,
            'exception' => $exception,
        ]);
    }
}
