<?php

use App\Services\Health\Component;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeHealthCheck;

it('prints a table and exits 0 when everything is up', function () {
    fakeHealthChecks();

    $this->artisan('app:health')
        ->expectsOutputToContain('Correlation ID:')
        ->expectsTable(['Component', 'Status', 'Reason', 'Latency'], [
            ['app', 'up', '', '0 ms'],
            ['database', 'up', '', '1 ms'],
            ['queue', 'up', '', '1 ms'],
            ['cache', 'up', '', '1 ms'],
            ['worker', 'up', '', '1 ms'],
            ['mail', 'up', '', '1 ms'],
        ])
        ->assertExitCode(0);
});

it('exits 1 when a component is down and shows its reason', function () {
    fakeHealthChecks(['mail' => new FakeHealthCheck(Component::Mail, 'SMTP authentication failed')]);

    $this->artisan('app:health')
        ->expectsOutputToContain('SMTP authentication failed')
        ->assertExitCode(1);
});

it('checks only the requested components', function () {
    $checks = fakeHealthChecks();

    $this->artisan('app:health', ['--only' => ['worker']])->assertExitCode(0);

    expect($checks['worker']->calls)->toBe(1)
        ->and($checks['database']->calls)->toBe(0)
        ->and($checks['mail']->calls)->toBe(0);
});

it('rejects an unknown component with exit code 2 and lists the allowed values', function () {
    $checks = fakeHealthChecks();

    $this->artisan('app:health', ['--only' => ['foo']])
        ->expectsOutputToContain('app, database, queue, cache, worker, mail')
        ->assertExitCode(2);

    expect($checks['database']->calls)->toBe(0);
});

it('prints the same schema as the HTTP endpoint with --json', function () {
    fakeHealthChecks();

    $exitCode = Artisan::call('app:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(array_keys($report))->toBe(['status', 'checked_at', 'correlation_id', 'components'])
        ->and($report['status'])->toBe('up')
        ->and(array_column($report['components'], 'name'))->toBe(['app', 'database', 'queue', 'cache', 'worker', 'mail'])
        ->and(array_keys($report['components'][0]))->toBe(['name', 'status', 'reason', 'latency_ms']);
});

it('always runs the checks in detailed mode so the mail check bypasses its cache', function () {
    $checks = fakeHealthChecks();

    $this->artisan('app:health')->assertExitCode(0);

    expect($checks['mail']->lastDetailed)->toBeTrue();
});
