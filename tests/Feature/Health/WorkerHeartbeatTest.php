<?php

use App\Services\Health\Checks\WorkerCheck;
use App\Services\Health\ComponentState;
use App\Services\Health\WorkerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('records the heartbeat as a unix timestamp that expires after 300 seconds', function () {
    Cache::spy();

    app(WorkerHeartbeat::class)->record();

    Cache::shouldHaveReceived('put')->with('health:worker:heartbeat', now()->timestamp, 300)->once();
});

it('writes the heartbeat at most once per 10 seconds', function () {
    $heartbeat = app(WorkerHeartbeat::class);
    $firstTimestamp = now()->timestamp;

    $heartbeat->record();
    Carbon::setTestNow(now()->addSeconds(9));
    $heartbeat->record();

    expect(Cache::get('health:worker:heartbeat'))->toBe($firstTimestamp);

    Carbon::setTestNow(now()->addSeconds(2));
    $heartbeat->record();

    expect(Cache::get('health:worker:heartbeat'))->toBe(now()->timestamp);
});

it('reports the worker up when the heartbeat is younger than 90 seconds', function () {
    app(WorkerHeartbeat::class)->record();
    Carbon::setTestNow(now()->addSeconds(89));

    $status = app(WorkerCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Up)
        ->and($status->reason)->toBeNull();
});

it('reports the worker down with the heartbeat age once it reaches 90 seconds', function () {
    app(WorkerHeartbeat::class)->record();
    Carbon::setTestNow(now()->addSeconds(90));

    $status = app(WorkerCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Down)
        ->and($status->reason)->toBe('No heartbeat for 90 s');
});

it('reports the worker down when no heartbeat was ever recorded', function () {
    $status = app(WorkerCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Down)
        ->and($status->reason)->toBe('No worker heartbeat recorded');
});

it('reports the worker down when the cache store is unreachable', function () {
    Log::spy();
    Cache::shouldReceive('get')->with('health:worker:heartbeat')->andThrow(new RuntimeException('Connection refused'));

    $status = app(WorkerCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Down)
        ->and($status->reason)->toBe('Heartbeat unavailable: cache store unreachable');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => ($context['exception'] ?? null) instanceof RuntimeException,
    )->once();
});

it('logs and rethrows when the heartbeat cannot be stored', function () {
    Log::spy();
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Connection refused'));

    expect(fn () => app(WorkerHeartbeat::class)->record())->toThrow(RuntimeException::class, 'Connection refused');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => ($context['exception'] ?? null) instanceof RuntimeException,
    )->once();
});
