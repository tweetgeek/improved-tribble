<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseCheck implements HealthCheck
{
    use ReportsFailures;

    public function component(): Component
    {
        return Component::Database;
    }

    public function check(bool $detailed): ComponentStatus
    {
        $startedAt = hrtime(true);

        try {
            DB::connection()->select('select 1');
        } catch (Throwable $exception) {
            $reason = $this->failureReason('Database connection failed', $exception, $detailed);
            $this->logFailure($reason, $exception);

            return ComponentStatus::down($this->component(), $reason, $this->elapsedMilliseconds($startedAt));
        }

        return ComponentStatus::up($this->component(), $this->elapsedMilliseconds($startedAt));
    }
}
