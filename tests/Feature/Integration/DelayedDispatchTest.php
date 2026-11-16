<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\AlwaysFailingJob;

pest()->group('integration');

it('keeps a delayed job invisible until its delay has passed', function () {
    $queueName = 'it-delay-'.Str::uuid();
    $connection = Queue::connection('redis');

    try {
        dispatch((new AlwaysFailingJob())->onConnection('redis')->onQueue($queueName)->delay(5));

        expect($connection->pop($queueName))->toBeNull();

        sleep(6);

        $job = $connection->pop($queueName);

        expect($job)->not->toBeNull()
            ->and($job->resolveName())->toBe(AlwaysFailingJob::class);

        $job->delete();
    } finally {
        Redis::connection('default')->del([
            "queues:{$queueName}",
            "queues:{$queueName}:delayed",
            "queues:{$queueName}:reserved",
            "queues:{$queueName}:notify",
        ]);
    }
});
