<?php

namespace App\Services\Health;

use Carbon\CarbonImmutable;

final readonly class HealthReport
{
    public ComponentState $status;

    /**
     * @param  list<ComponentStatus>  $components
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public string $correlationId,
        public array $components,
    ) {
        $this->status = array_all($components, fn (ComponentStatus $component): bool => $component->isUp())
            ? ComponentState::Up
            : ComponentState::Down;
    }

    public function isUp(): bool
    {
        return $this->status === ComponentState::Up;
    }

    /**
     * @return array{
     *     status: string,
     *     checked_at: string,
     *     correlation_id: string,
     *     components: list<array{name: string, status: string, reason: string|null, latency_ms: int|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'correlation_id' => $this->correlationId,
            'components' => array_map(
                fn (ComponentStatus $component): array => $component->toArray(),
                $this->components,
            ),
        ];
    }
}
