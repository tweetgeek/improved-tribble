<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use Illuminate\Support\Facades\Queue;
use Throwable;

class QueueCheck implements HealthCheck
{
    use ReportsFailures;

    public function component(): Component
    {
        return Component::Queue;
    }

    public function check(bool $detailed): ComponentStatus
    {
        $startedAt = hrtime(true);

        try {
            Queue::connection(config('queue.default'))->size('default');
        } catch (Throwable $exception) {
            $reason = $this->failureReason('Queue broker connection refused', $exception, $detailed);
            $this->logFailure($reason, $exception);

            return ComponentStatus::down($this->component(), $reason, $this->elapsedMilliseconds($startedAt));
        }

        return ComponentStatus::up($this->component(), $this->elapsedMilliseconds($startedAt));
    }
}
