<?php

namespace Tests\Support;

use App\Services\Health\Checks\HealthCheck;
use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use Throwable;

class FakeHealthCheck implements HealthCheck
{
    public int $calls = 0;

    public ?bool $lastDetailed = null;

    public function __construct(
        private readonly Component $component,
        private readonly ?string $downReason = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function component(): Component
    {
        return $this->component;
    }

    public function check(bool $detailed): ComponentStatus
    {
        $this->calls++;
        $this->lastDetailed = $detailed;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->downReason === null
            ? ComponentStatus::up($this->component, 1)
            : ComponentStatus::down($this->component, $this->downReason, 1);
    }
}
