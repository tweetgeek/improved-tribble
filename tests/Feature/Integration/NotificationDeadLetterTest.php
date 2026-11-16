<?php

use App\Enums\DeliveryStatus;
use App\Jobs\Notifications\SendSalesTeamNotification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\Notifications\PaymentEvents;

pest()->group('integration');

beforeEach(function () {
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.database' => env('INTEGRATION_DB_DATABASE', 'payment_gateway'),
        'queue.failed.database' => 'pgsql',
    ]);
    DB::purge('pgsql');

    $this->queueName = 'it-notify-'.Str::uuid();
    $this->eventId = 'evt_it_'.Str::lower(Str::random(12));
});

afterEach(function () {
    DB::connection('pgsql')->table('failed_jobs')->where('queue', $this->queueName)->delete();
    NotificationDelivery::query()->where('event_id', $this->eventId)->delete();

    Redis::connection('default')->del([
        "queues:{$this->queueName}",
        "queues:{$this->queueName}:delayed",
        "queues:{$this->queueName}:reserved",
        "queues:{$this->queueName}:notify",
    ]);
});

function dispatchSalesTeamJob(string $eventId, string $queue): void
{
    dispatch((new SendSalesTeamNotification(PaymentEvents::example(['eventId' => $eventId])))
        ->onConnection('redis')
        ->onQueue($queue));

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true]);
}

it('moves a permanently failing channel job straight to dead-letter in PostgreSQL', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'permanent']);
    $correlationId = 'it-notify-'.Str::random(8);
    Context::add('correlation_id', $correlationId);

    dispatchSalesTeamJob($this->eventId, $this->queueName);

    $failed = DB::connection('pgsql')->table('failed_jobs')->where('queue', $this->queueName)->first();

    expect($failed)->not->toBeNull()
        ->and($failed->exception)->toContain('PermanentChannelException');

    $payload = json_decode($failed->payload, true);

    expect($payload['displayName'])->toBe(SendSalesTeamNotification::class.':salesteam')
        ->and(json_encode($payload['illuminate:log:context']))->toContain($correlationId)
        ->and(NotificationDelivery::query()->where('event_id', $this->eventId)->sole()->status)->toBe(DeliveryStatus::Failed);
});

it('releases a transiently failing channel job with a 60 s delay', function () {
    config(['notifications.channels.salesteam.mock.mode' => 'transient']);
    $before = now()->getTimestamp();

    dispatchSalesTeamJob($this->eventId, $this->queueName);

    $delayed = Redis::connection('default')->zrange("queues:{$this->queueName}:delayed", 0, -1, ['withscores' => true]);

    expect($delayed)->toHaveCount(1)
        ->and((int) array_values($delayed)[0])->toBeBetween($before + 55, $before + 65)
        ->and(json_decode(array_key_first($delayed), true)['attempts'])->toBe(1)
        ->and(DB::connection('pgsql')->table('failed_jobs')->where('queue', $this->queueName)->exists())->toBeFalse()
        ->and(NotificationDelivery::query()->where('event_id', $this->eventId)->sole()->status)->toBe(DeliveryStatus::Pending);
});
