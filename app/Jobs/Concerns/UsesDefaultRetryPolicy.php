<?php

namespace App\Jobs\Concerns;

/**
 * Applies config('queue.retry_policy') to a queued job: attempts, rising backoff, timeout and
 * the number of tolerated exceptions.
 *
 * The queue reads `tries()` and `backoff()` as methods, but `$timeout` and `$maxExceptions`
 * only as properties, which cannot be initialised from config. Their defaults below mirror the
 * configuration, and tests/Unit/Queue/RetryPolicyTest.php fails when the two drift apart.
 *
 * A job using this trait MUST still implement `failed(Throwable $exception)` and log the
 * failure (constitution, principle III).
 */
trait UsesDefaultRetryPolicy
{
    public int $timeout = 60;

    public int $maxExceptions = 3;

    public function tries(): int
    {
        return $this->defaultTries();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(config('queue.retry_policy.backoff'));
    }

    protected function defaultTries(): int
    {
        return (int) config('queue.retry_policy.tries');
    }
}
