<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendChannelNotification;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Enabled notification channels from config('notifications.channels').
 */
final class NotificationChannelRegistry
{
    /**
     * @return array<string, class-string<SendChannelNotification>>
     *
     * @throws LogicException When a channel is registered with a job that is not a channel job.
     */
    public function enabled(): array
    {
        $jobs = [];

        foreach ((array) config('notifications.channels', []) as $channel => $definition) {
            if (! ($definition['enabled'] ?? false)) {
                continue;
            }

            $job = $definition['job'] ?? null;

            if (! is_string($job) || ! is_subclass_of($job, SendChannelNotification::class)) {
                Log::error('notifications.invalid_channel_config', ['channel' => $channel, 'job' => $job]);

                throw new LogicException("Notification channel [{$channel}] must use a job extending ".SendChannelNotification::class.'.');
            }

            $jobs[$channel] = $job;
        }

        return $jobs;
    }
}
