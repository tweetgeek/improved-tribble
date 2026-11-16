<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class CacheCheck implements HealthCheck
{
    use ReportsFailures;

    public function component(): Component
    {
        return Component::Cache;
    }

    public function check(bool $detailed): ComponentStatus
    {
        $startedAt = hrtime(true);
        $key = 'health:cache:probe:'.Str::uuid();
        $value = Str::random(16);

        try {
            Cache::put($key, $value, 10);
            $readBack = Cache::get($key);
            Cache::forget($key);
        } catch (Throwable $exception) {
            $reason = $this->failureReason('Cache store connection refused', $exception, $detailed);
            $this->logFailure($reason, $exception);

            return ComponentStatus::down($this->component(), $reason, $this->elapsedMilliseconds($startedAt));
        }

        if ($readBack !== $value) {
            return ComponentStatus::down($this->component(), 'Cache read-back mismatch', $this->elapsedMilliseconds($startedAt));
        }

        return ComponentStatus::up($this->component(), $this->elapsedMilliseconds($startedAt));
    }
}
