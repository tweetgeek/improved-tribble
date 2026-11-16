<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\AlwaysFailingJob;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $context
 */
function hasJobLogContext(array $context): bool
{
    foreach (['job', 'queue', 'connection', 'attempt', 'uuid', 'payload', 'exception'] as $key) {
        if (! array_key_exists($key, $context)) {
            return false;
        }
    }

    return $context['job'] === AlwaysFailingJob::class
        && $context['exception'] instanceof RuntimeException
        && is_array($context['payload'])
        && is_string($context['uuid']);
}

function runDatabaseWorkerOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

it('logs every failed attempt with the job, queue, attempt, uuid, payload and exception', function () {
    Log::spy();
    dispatch((new AlwaysFailingJob())->onConnection('database'));

    runDatabaseWorkerOnce();

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'Job attempt failed'
            && hasJobLogContext($context)
            && $context['attempt'] === 1,
    )->once();
    Log::shouldNotHaveReceived('error', fn ($message): bool => $message === 'Job moved to dead-letter');
});

it('logs the move to dead-letter once the attempts are exhausted and stores the job in failed_jobs', function () {
    Log::spy();
    dispatch((new AlwaysFailingJob(maxAttempts: 1))->onConnection('database'));

    runDatabaseWorkerOnce();

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'Job moved to dead-letter' && hasJobLogContext($context),
    )->once();

    $failed = DB::table('failed_jobs')->first();

    expect($failed)->not->toBeNull()
        ->and($failed->queue)->toBe('default')
        ->and($failed->exception)->toContain('Simulated job failure')
        ->and(json_decode($failed->payload, true)['displayName'])->toBe(AlwaysFailingJob::class);
});

it('declares the default retry policy in the payload of dispatched jobs', function () {
    dispatch((new AlwaysFailingJob())->onConnection('database'));

    $payload = json_decode(DB::table('jobs')->value('payload'), true);

    expect($payload['maxTries'])->toBe(5)
        ->and($payload['backoff'])->toBe('10,30,90,270')
        ->and($payload['timeout'])->toBe(60)
        ->and($payload['maxExceptions'])->toBe(3);
});
