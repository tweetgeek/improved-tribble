<?php

use App\Events\PaymentEventReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'webhooks.providers.generic.secret' => 'whsec_test_secret',
        'webhooks.providers.generic.previous_secret' => null,
        'webhooks.providers.generic.tolerance' => 300,
    ]);

    Event::fake([PaymentEventReceived::class]);
    $this->logs = LogCapture::start();
});

/**
 * @return list<LogRecord>
 */
function logRecords(TestHandler $handler, string $message): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record): bool => $record->message === $message,
    ));
}

function expectRejectedAndNothingStored(TestHandler $handler, string $reason): void
{
    $rejections = logRecords($handler, 'webhook.rejected');

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]->level)->toBe(Level::Error)
        ->and($rejections[0]->context['reason'])->toBe($reason)
        ->and($rejections[0]->context)->toHaveKeys(['provider', 'reason', 'headers', 'body']);

    test()->assertDatabaseCount('webhook_events', 0);
    Event::assertNotDispatched(PaymentEventReceived::class);
}

it('rejects a webhook without the X-Payment-Provider header as an unknown provider', function () {
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Payment-Provider' => null])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unknown_provider')
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);

    expectRejectedAndNothingStored($this->logs, 'unknown_provider');
});

it('rejects a webhook from an unregistered or malformed provider identifier', function (string $provider) {
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Payment-Provider' => $provider])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unknown_provider');

    expectRejectedAndNothingStored($this->logs, 'unknown_provider');
})->with(['unregistered' => 'stripe', 'malformed' => 'Generic!']);

it('rejects a webhook without a signature header', function () {
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Webhook-Signature' => null])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature')
        ->assertJsonPath('error.message', 'Webhook signature verification failed.');

    expectRejectedAndNothingStored($this->logs, 'missing_signature');
});

it('rejects a webhook signed with a wrong secret', function () {
    $this->postSignedWebhook($this->examplePayload(), secret: 'wrong_secret')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature');

    expectRejectedAndNothingStored($this->logs, 'invalid_signature');
});

it('rejects a replayed webhook whose signature timestamp is outside the window', function () {
    $this->travelTo(now());

    $this->postSignedWebhook($this->examplePayload(), timestamp: now()->getTimestamp() - 400)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature');

    expectRejectedAndNothingStored($this->logs, 'signature_expired');
});

it('authenticates before parsing or validating the body', function (string $rawBody) {
    $this->postSignedWebhook($rawBody, secret: 'wrong_secret')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature');

    expectRejectedAndNothingStored($this->logs, 'invalid_signature');
})->with([
    'malformed JSON' => '{"event":',
    'invalid payload' => fn () => json_encode(test()->examplePayload(['data' => ['transaction' => ['amount' => ['value' => -5]]]])),
]);

it('masks the signature header in the logged rejection', function () {
    $this->postSignedWebhook($this->examplePayload(), secret: 'wrong_secret');

    $logged = json_encode(logRecords($this->logs, 'webhook.rejected')[0]->context['headers']);

    expect($logged)->toContain('[REDACTED]')
        ->and($logged)->not->toMatch('/v1=[0-9a-f]{64}/')
        ->and($logged)->toContain('generic');
});

it('accepts a webhook signed with the previous secret during rotation', function () {
    config(['webhooks.providers.generic.previous_secret' => 'whsec_old_secret']);

    $this->postSignedWebhook($this->examplePayload(), secret: 'whsec_old_secret')
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect(logRecords($this->logs, 'webhook.accepted')[0]->context['verified_with'])->toBe('previous');
});

it('reports the current secret when it verified the signature', function () {
    $this->postSignedWebhook($this->examplePayload())->assertOk();

    expect(logRecords($this->logs, 'webhook.accepted')[0]->context['verified_with'])->toBe('current');
});

it('rejects the old secret once the previous secret is removed', function () {
    $this->postSignedWebhook($this->examplePayload(), secret: 'whsec_old_secret')
        ->assertStatus(401);

    expectRejectedAndNothingStored($this->logs, 'invalid_signature');
});

it('answers 500 and logs the misconfiguration when the provider has no secret', function () {
    config(['webhooks.providers.generic.secret' => null]);

    $this->postSignedWebhook($this->examplePayload(), secret: 'anything')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error');

    $misconfigured = logRecords($this->logs, 'webhook.provider_misconfigured');

    expect($misconfigured)->toHaveCount(1)
        ->and($misconfigured[0]->level)->toBe(Level::Error)
        ->and($misconfigured[0]->context['provider'])->toBe('generic')
        ->and($misconfigured[0]->context['missing'])->toBe(['secret']);

    $this->assertDatabaseCount('webhook_events', 0);
});
