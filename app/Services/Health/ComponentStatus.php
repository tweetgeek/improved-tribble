<?php

namespace App\Services\Health;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ComponentStatus
{
    /**
     * `reason` is set if and only if the component is down. `info` is an optional,
     * operator-facing remark for an up component and is shown only by the CLI.
     */
    public function __construct(
        public Component $name,
        public ComponentState $state,
        public ?string $reason,
        public ?int $latencyMs,
        public CarbonImmutable $checkedAt,
        public ?string $info = null,
    ) {
        if (($state === ComponentState::Up) !== ($reason === null)) {
            throw new InvalidArgumentException('A health reason must be set if and only if the component is down.');
        }
    }

    public static function up(Component $name, ?int $latencyMs = null, ?string $info = null): self
    {
        return new self($name, ComponentState::Up, null, $latencyMs, CarbonImmutable::now(), $info);
    }

    public static function down(Component $name, string $reason, ?int $latencyMs = null): self
    {
        return new self($name, ComponentState::Down, $reason, $latencyMs, CarbonImmutable::now());
    }

    public function isUp(): bool
    {
        return $this->state === ComponentState::Up;
    }

    /**
     * @return array{name: string, status: string, reason: string|null, latency_ms: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name->value,
            'status' => $this->state->value,
            'reason' => $this->reason,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
