<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Notifications\Channels\MailtrapChannel;

final class SendMailtrapNotification extends SendChannelNotification
{
    protected function channel(): NotificationChannel
    {
        return app(MailtrapChannel::class);
    }
}
