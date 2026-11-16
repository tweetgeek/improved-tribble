<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ReportsFailures
{
    abstract public function component(): Component;

    private function elapsedMilliseconds(int $startedAtNanoseconds): int
    {
        return (int) ((hrtime(true) - $startedAtNanoseconds) / 1_000_000);
    }

    /**
     * The HTTP-safe reason, extended with the exception class and message in detailed (CLI) mode.
     */
    private function failureReason(string $reason, Throwable $exception, bool $detailed): string
    {
        return $detailed
            ? sprintf('%s (%s: %s)', $reason, class_basename($exception), $exception->getMessage())
            : $reason;
    }

    private function logFailure(string $reason, Throwable $exception): void
    {
        Log::error('Health check failed', [
            'component' => $this->component()->value,
            'reason' => $reason,
            'exception' => $exception,
        ]);
    }
}
