<?php

use App\Jobs\Notifications\SendMailtrapNotification;
use App\Jobs\Notifications\SendSalesTeamNotification;

$notifyTestEvents = env('NOTIFICATIONS_NOTIFY_TEST_EVENTS');

return [

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    |
    | Time zone used for dates in notifications (the application itself stays in
    | UTC) and Polish labels shown next to the raw event type.
    |
    */

    'timezone' => 'Europe/Warsaw',

    'event_labels' => [
        'charge.successful' => 'Płatność zakończona sukcesem',
        'charge.failed' => 'Płatność nieudana',
        'charge.refunded' => 'Zwrot płatności',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Events
    |--------------------------------------------------------------------------
    |
    | Whether events with "livemode": false trigger notifications. When the
    | variable is not set, test events are notified everywhere except production.
    |
    */

    'notify_test_events' => in_array($notifyTestEvents, [null, ''], true)
        ? env('APP_ENV', 'production') !== 'production'
        : filter_var($notifyTestEvents, FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | Every enabled channel receives each accepted payment event through its own
    | queued job. "job" MUST extend App\Jobs\Notifications\SendChannelNotification.
    | Adding a channel means adding a channel class, its job and an entry below.
    |
    */

    'channels' => [
        'mailtrap' => [
            'enabled' => (bool) env('NOTIFICATIONS_MAILTRAP_ENABLED', true),
            'job' => SendMailtrapNotification::class,
            'recipients' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('NOTIFICATIONS_SALES_TEAM_EMAILS', 'sales@example.test')),
            ))),
        ],

        'salesteam' => [
            'enabled' => (bool) env('NOTIFICATIONS_SALESTEAM_ENABLED', true),
            'job' => SendSalesTeamNotification::class,
            'mock' => [
                // success | transient | permanent | fail_first_n
                'mode' => env('SALESTEAM_MOCK_MODE', 'success'),
                'fail_first' => (int) env('SALESTEAM_MOCK_FAIL_FIRST', 2),
            ],
        ],
    ],

];
