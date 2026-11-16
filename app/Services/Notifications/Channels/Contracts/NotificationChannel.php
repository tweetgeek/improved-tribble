<?php

namespace App\Services\Notifications\Channels\Contracts;

use App\Services\Payments\Data\PaymentNotification;

/**
 * A sales team notification channel (contracts/notification-channels.md §1.1).
 *
 * A channel translates errors of its integration into TransientChannelException (retry) or
 * PermanentChannelException (dead-letter at once), keeps every network call below the 30 s job
 * timeout, never formats data itself and never retries or swallows errors on its own.
 */
interface NotificationChannel
{
    /**
     * The channel key, identical to its key in config('notifications.channels').
     */
    public function name(): string;

    /**
     * Delivers the notification; returning without an exception confirms the delivery.
     */
    public function send(PaymentNotification $notification): void;
}
