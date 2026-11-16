<?php

namespace App\Console\Commands;

use App\Services\Health\Component;
use App\Services\Health\ComponentStatus;
use App\Services\Health\HealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

#[Signature('app:health
    {--only=* : Check only the given components: app, database, queue, cache, worker, mail}
    {--json : Print the report as JSON (same schema as GET /health)}')]
#[Description('Check the health of the application and its dependencies')]
class CheckHealthCommand extends Command
{
    private const EXIT_INVALID_OPTION = 2;

    public function handle(HealthChecker $checker): int
    {
        $only = $this->requestedComponents();

        if ($only === null && $this->option('only') !== []) {
            return self::EXIT_INVALID_OPTION;
        }

        if (! Context::has('correlation_id')) {
            Context::add('correlation_id', (string) Str::uuid7());
        }

        $report = $checker->run(detailed: true, only: $only);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Correlation ID: '.$report->correlationId);
            $this->newLine();
            $this->table(
                ['Component', 'Status', 'Reason', 'Latency'],
                array_map(
                    fn (ComponentStatus $status): array => [
                        $status->name->value,
                        $status->state->value,
                        $status->reason ?? $status->info ?? '',
                        ($status->latencyMs ?? 0).' ms',
                    ],
                    $report->components,
                ),
            );
        }

        return $report->isUp() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<Component>|null Null when --only is absent (all components) or contains an unknown value.
     */
    private function requestedComponents(): ?array
    {
        $requested = (array) $this->option('only');

        if ($requested === []) {
            return null;
        }

        $components = [];

        foreach ($requested as $value) {
            $component = Component::tryFrom((string) $value);

            if ($component === null) {
                $this->error(sprintf(
                    'Unknown component "%s". Allowed values: %s.',
                    $value,
                    implode(', ', array_column(Component::cases(), 'value')),
                ));

                return null;
            }

            $components[] = $component;
        }

        return $components;
    }
}
