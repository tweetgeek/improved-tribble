<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Notifications\Channels\Exceptions\PermanentChannelException;
use App\Services\Notifications\Channels\Exceptions\TransientChannelException;
use App\Services\Payments\Data\PaymentNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Mock of the SalesTeam integration. It records every notification in the log and behaves like
 * a real integration according to config('notifications.channels.salesteam.mock.mode'):
 * success, transient (every attempt fails), permanent, or fail_first_n (the first N attempts of
 * each notification fail transiently, later ones succeed). The fail_first_n counter lives in the
 * cache, so it survives worker restarts between retries.
 */
final class SalesTeamChannel implements NotificationChannel
{
    private const COUNTER_TTL_SECONDS = 86400;

    public function name(): string
    {
        return 'salesteam';
    }

    public function send(PaymentNotification $notification): void
    {
        $mode = (string) config('notifications.channels.salesteam.mock.mode', 'success');

        Log::info('salesteam.mock.received', [
            'mode' => $mode,
            'notification' => $notification->toArray(),
        ]);

        match ($mode) {
            'success' => null,
            'transient' => throw new TransientChannelException('SalesTeam mock: simulated temporary outage.'),
            'permanent' => throw new PermanentChannelException('SalesTeam mock: notification rejected as invalid.'),
            'fail_first_n' => $this->failFirstAttempts($notification),
            default => throw new LogicException("Unknown SalesTeam mock mode [{$mode}]."),
        };
    }

    private function failFirstAttempts(PaymentNotification $notification): void
    {
        $key = "salesteam-mock:{$notification->deliveryKey}";
        $failFirst = (int) config('notifications.channels.salesteam.mock.fail_first', 2);

        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
        $attempt = (int) Cache::increment($key);

        if ($attempt <= $failFirst) {
            throw new TransientChannelException("SalesTeam mock: simulated failure of attempt {$attempt} of {$failFirst}.");
        }
    }
}
