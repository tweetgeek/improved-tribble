<?php

use App\Events\PaymentEventReceived;
use App\Models\WebhookEvent;
use App\Services\Payments\Data\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Monolog\LogRecord;
use Tests\Support\LogCapture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['webhooks.providers.generic.secret' => 'whsec_test_secret']);
});

it('accepts a valid webhook, stores it and publishes the payment event', function () {
    Event::fake([PaymentEventReceived::class]);
    $logs = LogCapture::start();

    $payload = $this->examplePayload(['extra_field' => 'kept']);
    $rawBody = (string) json_encode($payload);

    $response = $this->postSignedWebhook($rawBody, headers: ['X-Correlation-ID' => 'rw-test-1']);

    $response->assertOk()->assertExactJson([
        'status' => 'accepted',
        'message' => 'Event accepted for processing.',
        'event_id' => 'evt_9f8d7c6b5a4321',
        'correlation_id' => 'rw-test-1',
    ])->assertHeader('X-Correlation-ID', 'rw-test-1');

    $stored = WebhookEvent::sole();

    expect($stored->provider)->toBe('generic')
        ->and($stored->event_id)->toBe('evt_9f8d7c6b5a4321')
        ->and($stored->event_type)->toBe('charge.successful')
        ->and($stored->livemode)->toBeTrue()
        ->and($stored->occurred_at->toIso8601String())->toBe('2024-06-10T14:20:00+00:00')
        ->and($stored->payload)->toBe($payload)
        ->and($stored->payload['extra_field'])->toBe('kept')
        ->and($stored->payload_sha256)->toBe(hash('sha256', $rawBody))
        ->and($stored->correlation_id)->toBe('rw-test-1')
        ->and($stored->received_at)->not->toBeNull();

    Event::assertDispatchedTimes(PaymentEventReceived::class, 1);
    Event::assertDispatched(
        PaymentEventReceived::class,
        fn (PaymentEventReceived $event): bool => $event->paymentEvent instanceof PaymentEvent
            && $event->paymentEvent->provider === 'generic'
            && $event->paymentEvent->eventId === 'evt_9f8d7c6b5a4321'
            && $event->paymentEvent->amount->minorUnits === 29990,
    );

    $accepted = array_values(array_filter($logs->getRecords(), fn (LogRecord $r): bool => $r->message === 'webhook.accepted'));

    expect($accepted)->toHaveCount(1)
        ->and($accepted[0]->context)->toMatchArray([
            'provider' => 'generic',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'event_type' => 'charge.successful',
            'livemode' => true,
            'verified_with' => 'current',
        ]);
});

it('accepts every event type, not only successful charges', function () {
    Event::fake([PaymentEventReceived::class]);

    $this->postSignedWebhook($this->examplePayload(['event' => 'charge.failed', 'event_id' => 'evt_failed_1']))
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect(WebhookEvent::sole()->event_type)->toBe('charge.failed');
});

it('rejects a body that is not a JSON object with 400', function (string $rawBody) {
    Event::fake([PaymentEventReceived::class]);
    $logs = LogCapture::start();

    $this->postSignedWebhook($rawBody)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'malformed_payload');

    $this->assertDatabaseCount('webhook_events', 0);
    Event::assertNotDispatched(PaymentEventReceived::class);

    $rejections = array_values(array_filter($logs->getRecords(), fn (LogRecord $r): bool => $r->message === 'webhook.rejected'));

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]->context['reason'])->toBe('malformed_payload')
        ->and($rejections[0]->context['body'])->toBe($rawBody);
})->with([
    'truncated JSON' => '{"event":',
    'empty body' => '',
    'JSON scalar' => '"abc"',
    'JSON list' => '[1,2,3]',
]);

it('rejects an invalid payload with 422 and field details', function (array $overrides, string $field, ?Closure $mutate = null) {
    Event::fake([PaymentEventReceived::class]);

    $payload = $this->examplePayload($overrides);

    if ($mutate !== null) {
        $payload = $mutate($payload);
    }

    $this->postSignedWebhook($payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseCount('webhook_events', 0);
    Event::assertNotDispatched(PaymentEventReceived::class);
})->with([
    'missing event_id' => [[], 'event_id', function (array $p): array {
        unset($p['event_id']);

        return $p;
    }],
    'negative amount' => [['data' => ['transaction' => ['amount' => ['value' => -5]]]], 'data.transaction.amount.value'],
    'zero amount' => [['data' => ['transaction' => ['amount' => ['value' => 0]]]], 'data.transaction.amount.value'],
    'amount as string' => [['data' => ['transaction' => ['amount' => ['value' => '29990']]]], 'data.transaction.amount.value'],
    'fractional amount' => [['data' => ['transaction' => ['amount' => ['value' => 299.9]]]], 'data.transaction.amount.value'],
    'lowercase currency' => [['data' => ['transaction' => ['amount' => ['currency' => 'pln']]]], 'data.transaction.amount.currency'],
    'missing order id' => [[], 'data.metadata.order_id', function (array $p): array {
        unset($p['data']['metadata']['order_id']);

        return $p;
    }],
    'non boolean livemode' => [['livemode' => 'yes'], 'livemode'],
    'invalid customer email' => [['data' => ['customer' => ['email' => 'not-an-email']]], 'data.customer.email'],
]);

it('rolls the stored event back and answers 500 when publishing fails, then accepts the retry', function () {
    Event::listen(PaymentEventReceived::class, function (): void {
        throw new RuntimeException('queue down');
    });

    $this->postSignedWebhook($this->examplePayload())
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error');

    $this->assertDatabaseCount('webhook_events', 0);

    Event::fake([PaymentEventReceived::class]);

    $this->postSignedWebhook($this->examplePayload())
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    $this->assertDatabaseCount('webhook_events', 1);
});
