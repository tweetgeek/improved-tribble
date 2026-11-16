<?php

use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

uses(TestCase::class);

it('requires at least 4 attempts', function () {
    expect(config('queue.retry_policy.tries'))->toBeInt()->toBeGreaterThanOrEqual(4);
});

it('uses a strictly increasing backoff', function () {
    $backoff = config('queue.retry_policy.backoff');

    expect($backoff)->toBeArray()->not->toBeEmpty();

    for ($i = 1; $i < count($backoff); $i++) {
        expect($backoff[$i])->toBeGreaterThan($backoff[$i - 1]);
    }
});

it('keeps the job timeout below the redis retry_after', function () {
    expect(config('queue.retry_policy.timeout'))->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('does not allow more exceptions than attempts', function () {
    expect(config('queue.retry_policy.max_exceptions'))->toBeLessThanOrEqual(config('queue.retry_policy.tries'));
});

it('exposes the configured policy to jobs using the trait', function () {
    $job = new AlwaysFailingJob();

    expect($job->tries())->toBe(config('queue.retry_policy.tries'))
        ->and($job->backoff())->toBe(config('queue.retry_policy.backoff'))
        ->and($job->timeout)->toBe(config('queue.retry_policy.timeout'))
        ->and($job->maxExceptions)->toBe(config('queue.retry_policy.max_exceptions'));
});
