<?php

namespace Tests\Support;

use App\Jobs\Concerns\UsesDefaultRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

class AlwaysFailingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use UsesDefaultRetryPolicy;

    public function __construct(private readonly ?int $maxAttempts = null)
    {
    }

    public function tries(): int
    {
        return $this->maxAttempts ?? $this->defaultTries();
    }

    public function handle(): void
    {
        throw new RuntimeException('Simulated job failure');
    }

    /**
     * Logging of the failure is done by the global queue listeners under test.
     */
    public function failed(Throwable $exception): void
    {
    }
}
