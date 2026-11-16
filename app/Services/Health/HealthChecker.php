<?php

namespace App\Services\Health;

use App\Services\Health\Checks\HealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthChecker
{
    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function __construct(private readonly iterable $checks)
    {
    }

    /**
     * @param  list<Component>|null  $only  Limit the report to these components; null checks all of them.
     */
    public function run(bool $detailed, ?array $only = null): HealthReport
    {
        $checksByComponent = [];

        foreach ($this->checks as $check) {
            $checksByComponent[$check->component()->value] = $check;
        }

        $statuses = [];

        foreach (Component::cases() as $component) {
            if ($only !== null && ! in_array($component, $only, true)) {
                continue;
            }

            $status = $component === Component::App
                ? ComponentStatus::up($component, 0)
                : $this->runCheck($component, $checksByComponent[$component->value] ?? null, $detailed);

            if (! $status->isUp()) {
                Log::error('Health component is down', [
                    'component' => $component->value,
                    'reason' => $status->reason,
                ]);
            }

            $statuses[] = $status;
        }

        return new HealthReport(
            CarbonImmutable::now(),
            (string) Context::get('correlation_id', ''),
            $statuses,
        );
    }

    private function runCheck(Component $component, ?HealthCheck $check, bool $detailed): ComponentStatus
    {
        if ($check === null) {
            return ComponentStatus::down($component, 'No health check registered', 0);
        }

        $startedAt = hrtime(true);

        try {
            return $check->check($detailed);
        } catch (Throwable $exception) {
            $reason = $detailed
                ? sprintf('Health check failed unexpectedly (%s: %s)', class_basename($exception), $exception->getMessage())
                : 'Health check failed unexpectedly';

            Log::error('Health check failed', [
                'component' => $component->value,
                'reason' => $reason,
                'exception' => $exception,
            ]);

            return ComponentStatus::down($component, $reason, (int) ((hrtime(true) - $startedAt) / 1_000_000));
        }
    }
}
