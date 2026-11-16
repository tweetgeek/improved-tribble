<?php

namespace App\Jobs\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\NotificationDelivery;
use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Notifications\Channels\Exceptions\PermanentChannelException;
use App\Services\Payments\Data\PaymentNotification;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\PaymentNotificationFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one payment event to one notification channel (constitution, principle V).
 *
 * Every channel job inherits the same guarantees: 4 attempts with retries after 1, 5 and
 * 15 minutes, a 30 s timeout below the queue's retry_after, logging of every failure and the
 * move to dead-letter (contracts/notification-channels.md §1.2). A PermanentChannelException fails
 * the job at once; any other error is logged and rethrown so the queue retries it.
 *
 * Deliveries are idempotent per (provider, event_id, channel) in notification_deliveries: a retry
 * or a manual queue:retry never sends a notification the channel already confirmed (research R12).
 * Concrete jobs only name their channel and MUST NOT override handle() or failed().
 */
abstract class SendChannelNotification implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 4;

    /**
     * Seconds a single attempt may run; read by the queue as a property.
     */
    public int $timeout = 30;

    public function __construct(public PaymentEvent $paymentEvent)
    {
    }

    abstract protected function channel(): NotificationChannel;

    public function tries(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function displayName(): string
    {
        return static::class.':'.$this->channel()->name();
    }

    public function handle(PaymentNotificationFormatter $formatter): void
    {
        $channel = $this->channel();
        $delivery = $this->openDelivery($channel);

        if ($delivery->status === DeliveryStatus::Delivered) {
            Log::info('notification.already_delivered', $this->logContext($channel));

            return;
        }

        $delivery->forceFill(['status' => DeliveryStatus::Pending, 'attempts' => $delivery->attempts + 1])->save();

        $notification = $formatter->format($this->paymentEvent);

        try {
            $channel->send($notification);
        } catch (PermanentChannelException $exception) {
            $this->recordFailedAttempt($delivery, $channel, $notification, $exception, transient: false);
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->recordFailedAttempt($delivery, $channel, $notification, $exception, transient: true);

            throw $exception;
        }

        $delivery->forceFill(['status' => DeliveryStatus::Delivered, 'delivered_at' => now()])->save();

        Log::info('notification.delivered', [
            ...$this->logContext($channel),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * Runs on a fresh job instance once the attempts are exhausted or the job was failed manually.
     */
    public function failed(?Throwable $exception): void
    {
        $channel = $this->channel();

        NotificationDelivery::updateOrCreate(
            $this->deliveryKey($channel),
            [
                'status' => DeliveryStatus::Failed,
                'failed_at' => now(),
                'last_error' => $exception !== null ? $this->describe($exception) : null,
                'correlation_id' => (string) Context::get('correlation_id', ''),
            ],
        );

        Log::error('notification.dead_lettered', [
            ...$this->logContext($channel),
            'max_attempts' => $this->tries(),
            'notification' => app(PaymentNotificationFormatter::class)->format($this->paymentEvent)->toArray(),
            'exception' => $exception,
            'failed_at' => now()->toIso8601String(),
        ]);
    }

    private function openDelivery(NotificationChannel $channel): NotificationDelivery
    {
        $delivery = NotificationDelivery::createOrFirst(
            $this->deliveryKey($channel),
            [
                'status' => DeliveryStatus::Pending,
                'attempts' => 0,
                'correlation_id' => (string) Context::get('correlation_id', ''),
            ],
        );

        return $delivery->refresh();
    }

    private function recordFailedAttempt(
        NotificationDelivery $delivery,
        NotificationChannel $channel,
        PaymentNotification $notification,
        Throwable $exception,
        bool $transient,
    ): void {
        $delivery->forceFill(['last_error' => $this->describe($exception)])->save();

        Log::error('notification.attempt_failed', [
            ...$this->logContext($channel),
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries(),
            'transient' => $transient,
            'notification' => $notification->toArray(),
            'exception' => $exception,
        ]);
    }

    /**
     * @return array{provider: string, event_id: string, channel: string}
     */
    private function deliveryKey(NotificationChannel $channel): array
    {
        return [
            'provider' => $this->paymentEvent->provider,
            'event_id' => $this->paymentEvent->eventId,
            'channel' => $channel->name(),
        ];
    }

    private function describe(Throwable $exception): string
    {
        return Str::limit($exception::class.': '.$exception->getMessage(), NotificationDelivery::LAST_ERROR_MAX_LENGTH, '');
    }

    /**
     * @return array{channel: string, provider: string, event_id: string}
     */
    protected function logContext(NotificationChannel $channel): array
    {
        return [
            'channel' => $channel->name(),
            'provider' => $this->paymentEvent->provider,
            'event_id' => $this->paymentEvent->eventId,
        ];
    }
}
