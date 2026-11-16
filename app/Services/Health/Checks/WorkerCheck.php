<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use App\Services\Health\WorkerHeartbeat;
use Throwable;

class WorkerCheck implements HealthCheck
{
    use ReportsFailures;

    private const MAX_HEARTBEAT_AGE_SECONDS = 90;

    public function __construct(private readonly WorkerHeartbeat $heartbeat)
    {
    }

    public function component(): Component
    {
        return Component::Worker;
    }

    public function check(bool $detailed): ComponentStatus
    {
        $startedAt = hrtime(true);

        try {
            $lastSeenAt = $this->heartbeat->lastSeenAt();
        } catch (Throwable $exception) {
            $reason = $this->failureReason('Heartbeat unavailable: cache store unreachable', $exception, $detailed);
            $this->logFailure($reason, $exception);

            return ComponentStatus::down($this->component(), $reason, $this->elapsedMilliseconds($startedAt));
        }

        if ($lastSeenAt === null) {
            return ComponentStatus::down($this->component(), 'No worker heartbeat recorded', $this->elapsedMilliseconds($startedAt));
        }

        $ageSeconds = max(0, now()->timestamp - $lastSeenAt->timestamp);

        if ($ageSeconds >= self::MAX_HEARTBEAT_AGE_SECONDS) {
            return ComponentStatus::down($this->component(), "No heartbeat for {$ageSeconds} s", $this->elapsedMilliseconds($startedAt));
        }

        return ComponentStatus::up($this->component(), $this->elapsedMilliseconds($startedAt), "last heartbeat {$ageSeconds} s ago");
    }
}
