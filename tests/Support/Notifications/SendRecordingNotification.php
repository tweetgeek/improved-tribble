<?php

namespace Tests\Support\Notifications;

use App\Jobs\Notifications\SendChannelNotification;
use App\Services\Notifications\Channels\Contracts\NotificationChannel;

/**
 * Job of the test-only RecordingChannel; it inherits every guarantee of SendChannelNotification.
 */
final class SendRecordingNotification extends SendChannelNotification
{
    protected function channel(): NotificationChannel
    {
        return app(RecordingChannel::class);
    }
}
