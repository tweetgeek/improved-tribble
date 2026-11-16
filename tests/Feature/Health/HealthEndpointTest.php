<?php

use App\Services\Health\Component;
use App\Services\Health\HealthChecker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeHealthCheck;
use Tests\Support\FakeSmtpTransport;

/**
 * @param  array<string, FakeHealthCheck>  $overrides  Keyed by component value.
 * @return array<string, FakeHealthCheck>
 */
function fakeHealthChecks(array $overrides = []): array
{
    $checks = [];

    foreach (Component::cases() as $component) {
        if ($component !== Component::App) {
            $checks[$component->value] = $overrides[$component->value] ?? new FakeHealthCheck($component);
        }
    }

    app()->bind(HealthChecker::class, fn (): HealthChecker => new HealthChecker(array_values($checks)));

    return $checks;
}

it('reports every component as up with the contracted shape', function () {
    fakeHealthChecks();

    $response = $this->getJson('/health', ['X-Correlation-ID' => 'health-test-1'])->assertOk();

    expect($response->json('status'))->toBe('up')
        ->and(array_column($response->json('components'), 'name'))->toBe(['app', 'database', 'queue', 'cache', 'worker', 'mail'])
        ->and(array_unique(array_column($response->json('components'), 'status')))->toBe(['up'])
        ->and(array_filter(array_column($response->json('components'), 'reason')))->toBeEmpty()
        ->and($response->json('correlation_id'))->toBe('health-test-1')
        ->and($response->headers->get('X-Correlation-ID'))->toBe('health-test-1')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers 503 and gives a reason only for the failing component', function () {
    fakeHealthChecks(['queue' => new FakeHealthCheck(Component::Queue, 'Queue broker connection refused')]);

    $response = $this->getJson('/health')->assertStatus(503);

    $components = collect($response->json('components'))->keyBy('name');

    expect($response->json('status'))->toBe('down')
        ->and($components['queue']['status'])->toBe('down')
        ->and($components['queue']['reason'])->toBe('Queue broker connection refused')
        ->and($components->except('queue')->pluck('reason')->filter())->toBeEmpty();
});

it('turns an exception thrown by a check into a down component and logs it', function () {
    Log::spy();
    fakeHealthChecks(['database' => new FakeHealthCheck(Component::Database, throws: new RuntimeException('connection to db-host-1 refused'))]);

    $response = $this->getJson('/health')->assertStatus(503);

    $database = collect($response->json('components'))->firstWhere('name', 'database');

    expect($database['status'])->toBe('down')
        ->and($database['reason'])->not->toBeEmpty()
        ->and($database['reason'])->not->toContain('db-host-1');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => ($context['component'] ?? null) === 'database'
            && ! empty($context['reason'])
            && ($context['exception'] ?? null) instanceof RuntimeException,
    )->once();
});

it('logs every down component at error level even without an exception', function () {
    Log::spy();
    fakeHealthChecks(['cache' => new FakeHealthCheck(Component::Cache, 'Cache store connection refused')]);

    $this->getJson('/health')->assertStatus(503);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => ($context['component'] ?? null) === 'cache'
            && ($context['reason'] ?? null) === 'Cache store connection refused',
    )->once();
});

it('never exposes configuration values in the HTTP reasons', function () {
    config([
        'mail.mailers.smtp.host' => 'mail-secret-host.test',
        'mail.mailers.smtp.username' => 'mail-secret-user',
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db-secret-host.test',
    ]);
    Mail::extend('smtp', fn (): FakeSmtpTransport => new FakeSmtpTransport(
        new TransportException('Authentication failed for mail-secret-user on mail-secret-host.test', 535),
    ));

    $body = $this->getJson('/health')->assertStatus(503)->getContent();

    expect($body)->not->toContain('mail-secret-host.test')
        ->and($body)->not->toContain('mail-secret-user')
        ->and($body)->not->toContain('db-secret-host.test');
});

it('serves the mail result from cache within 60 seconds', function () {
    config(['mail.mailers.smtp.username' => 'mail-user']);
    $transport = new FakeSmtpTransport();
    Mail::extend('smtp', fn (): FakeSmtpTransport => $transport);

    $this->getJson('/health');
    $this->getJson('/health');

    expect($transport->starts)->toBe(1);
});
