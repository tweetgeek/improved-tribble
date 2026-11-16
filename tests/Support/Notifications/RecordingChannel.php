<?php

namespace Tests\Support\Notifications;

use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Payments\Data\PaymentNotification;
use Throwable;

/**
 * A channel registered only in tests. It records every notification it receives and can be told
 * to throw, which lets tests exercise the guarantees of SendChannelNotification.
 */
final class RecordingChannel implements NotificationChannel
{
    /**
     * @var list<PaymentNotification>
     */
    public static array $received = [];

    public static ?Throwable $failWith = null;

    public static function reset(): void
    {
        self::$received = [];
        self::$failWith = null;
    }

    public function name(): string
    {
        return 'recording';
    }

    public function send(PaymentNotification $notification): void
    {
        if (self::$failWith !== null) {
            throw self::$failWith;
        }

        self::$received[] = $notification;
    }
}
