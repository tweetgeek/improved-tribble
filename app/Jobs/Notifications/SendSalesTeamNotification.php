<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Notifications\Channels\SalesTeamChannel;

final class SendSalesTeamNotification extends SendChannelNotification
{
    protected function channel(): NotificationChannel
    {
        return app(SalesTeamChannel::class);
    }
}
