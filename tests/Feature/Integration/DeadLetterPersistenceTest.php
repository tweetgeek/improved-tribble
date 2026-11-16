<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\AlwaysFailingJob;

pest()->group('integration');

beforeEach(function () {
    config([
        'database.connections.pgsql.database' => env('INTEGRATION_DB_DATABASE', 'payment_gateway'),
        'queue.failed.database' => 'pgsql',
    ]);

    $this->queueName = 'it-dlq-'.Str::uuid();
});

afterEach(function () {
    DB::connection('pgsql')->table('failed_jobs')->where('queue', $this->queueName)->delete();

    Redis::connection('default')->del([
        "queues:{$this->queueName}",
        "queues:{$this->queueName}:delayed",
        "queues:{$this->queueName}:reserved",
        "queues:{$this->queueName}:notify",
    ]);
});

it('persists an exhausted job in PostgreSQL with its correlation id, and can retry it', function () {
    $correlationId = 'it-dlq-'.Str::random(8);
    Context::add('correlation_id', $correlationId);

    dispatch((new AlwaysFailingJob(maxAttempts: 1))->onConnection('redis')->onQueue($this->queueName));

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $this->queueName, '--once' => true]);

    $failed = DB::connection('pgsql')->table('failed_jobs')->where('queue', $this->queueName)->first();

    expect($failed)->not->toBeNull()
        ->and($failed->connection)->toBe('redis')
        ->and($failed->exception)->toContain('Simulated job failure');

    $payload = json_decode($failed->payload, true);

    expect(json_encode($payload['illuminate:log:context']))->toContain($correlationId);

    expect(Queue::connection('redis')->size($this->queueName))->toBe(0);

    Artisan::call('queue:retry', ['id' => [$failed->uuid]]);

    expect(Queue::connection('redis')->size($this->queueName))->toBe(1)
        ->and(DB::connection('pgsql')->table('failed_jobs')->where('uuid', $failed->uuid)->exists())->toBeFalse();
});
