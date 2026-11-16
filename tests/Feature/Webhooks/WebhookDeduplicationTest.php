<?php

use App\Events\PaymentEventReceived;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\LogCapture;
use Tests\Support\Webhooks\AcmeTestProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'webhooks.providers.generic.secret' => 'whsec_test_secret',
        'webhooks.providers.acme' => [
            'driver' => AcmeTestProvider::class,
            'secret' => 'acme_secret',
            'previous_secret' => null,
            'tolerance' => 300,
        ],
    ]);

    Event::fake([PaymentEventReceived::class]);
    $this->logs = LogCapture::start();
});

/**
 * @return list<LogRecord>
 */
function dedupLogs(Monolog\Handler\TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

it('answers a repeated delivery with 200 duplicate and publishes the event only once', function () {
    $this->postSignedWebhook($this->examplePayload())->assertOk()->assertJsonPath('status', 'accepted');

    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Correlation-ID' => 'dup-2'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'duplicate',
            'message' => 'Event already received and is being processed.',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'correlation_id' => 'dup-2',
        ]);

    $this->assertDatabaseCount('webhook_events', 1);
    Event::assertDispatchedTimes(PaymentEventReceived::class, 1);

    $duplicates = dedupLogs($this->logs, 'webhook.duplicate');

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]->level)->toBe(Level::Info)
        ->and($duplicates[0]->context)->toMatchArray(['provider' => 'generic', 'event_id' => 'evt_9f8d7c6b5a4321'])
        ->and(dedupLogs($this->logs, 'webhook.accepted'))->toHaveCount(1);
});

it('keeps the correlation id of the original delivery on the stored event', function () {
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Correlation-ID' => 'dup-original']);
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Correlation-ID' => 'dup-retry'])
        ->assertJsonPath('correlation_id', 'dup-retry');

    expect(WebhookEvent::sole()->correlation_id)->toBe('dup-original');
});

it('treats the same event id with a different body as a duplicate and warns about the mismatch', function () {
    $original = (string) json_encode($this->examplePayload());
    $changed = (string) json_encode($this->examplePayload(['data' => ['transaction' => ['status' => 'REFUNDED']]]));

    $this->postSignedWebhook($original)->assertOk();
    $this->postSignedWebhook($changed)->assertOk()->assertJsonPath('status', 'duplicate');

    $mismatches = dedupLogs($this->logs, 'webhook.duplicate_payload_mismatch');

    expect($mismatches)->toHaveCount(1)
        ->and($mismatches[0]->level)->toBe(Level::Warning)
        ->and($mismatches[0]->context['stored_sha256'])->toBe(hash('sha256', $original))
        ->and($mismatches[0]->context['received_sha256'])->toBe(hash('sha256', $changed));

    expect(WebhookEvent::sole()->payload['data']['transaction']['status'])->toBe('COMPLETED');
    Event::assertDispatchedTimes(PaymentEventReceived::class, 1);
});

it('does not register the event id of a rejected delivery', function (Closure $reject) {
    $reject($this);

    $this->assertDatabaseCount('webhook_events', 0);

    $this->postSignedWebhook($this->examplePayload())->assertOk()->assertJsonPath('status', 'accepted');
})->with([
    'rejected with 401' => fn () => fn ($test) => $test->postSignedWebhook($test->examplePayload(), secret: 'wrong')->assertStatus(401),
    'rejected with 422' => fn () => fn ($test) => $test->postSignedWebhook(
        $test->examplePayload(['data' => ['transaction' => ['amount' => ['value' => -5]]]]),
    )->assertStatus(422),
]);

it('accepts the same event id from two different providers as separate events', function () {
    $this->postSignedWebhook($this->examplePayload())->assertOk()->assertJsonPath('status', 'accepted');
    $this->postSignedWebhook($this->examplePayload(), provider: 'acme')->assertOk()->assertJsonPath('status', 'accepted');

    expect(WebhookEvent::query()->orderBy('provider')->pluck('provider')->all())->toBe(['acme', 'generic']);
    Event::assertDispatchedTimes(PaymentEventReceived::class, 2);
});
