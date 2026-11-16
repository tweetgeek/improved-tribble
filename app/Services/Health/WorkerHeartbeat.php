<?php

namespace App\Services\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkerHeartbeat
{
    public const CACHE_KEY = 'health:worker:heartbeat';

    private const TTL_SECONDS = 300;

    private const THROTTLE_SECONDS = 10;

    private ?int $lastRecordedAt = null;

    /**
     * Stores the current time as the worker's last sign of life, at most once per 10 seconds per process.
     *
     * @throws Throwable When the cache store is unreachable, after logging it.
     */
    public function record(): void
    {
        $now = now()->timestamp;

        if ($this->lastRecordedAt !== null && $now - $this->lastRecordedAt < self::THROTTLE_SECONDS) {
            return;
        }

        try {
            Cache::put(self::CACHE_KEY, $now, self::TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::error('Worker heartbeat could not be recorded', [
                'cache_key' => self::CACHE_KEY,
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->lastRecordedAt = $now;
    }

    /**
     * @throws Throwable When the cache store is unreachable.
     */
    public function lastSeenAt(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }
}
