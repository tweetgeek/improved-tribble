<?php

use App\Events\PaymentEventReceived;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
});

it('accepts webhooks of a provider added only through configuration', function () {
    $this->postSignedWebhook($this->examplePayload(), provider: 'acme')
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    expect(WebhookEvent::sole()->provider)->toBe('acme');
    Event::assertDispatched(PaymentEventReceived::class, fn (PaymentEventReceived $e): bool => $e->paymentEvent->provider === 'acme');
});

it('gives the new provider the same rejections as the existing one', function (?string $rawBody, ?string $secret, int $status, string $code) {
    $this->postSignedWebhook($rawBody ?? $this->examplePayload(), secret: $secret, provider: 'acme')
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code);

    $this->assertDatabaseCount('webhook_events', 0);
})->with([
    'bad signature' => [null, 'wrong', 401, 'invalid_signature'],
    'malformed JSON' => ['{"event":', null, 400, 'malformed_payload'],
    'invalid payload' => ['{"event":"charge.successful","event_id":"evt_x","timestamp":1718029200,"data":{"transaction":{"id":"t","amount":{"value":-5,"currency":"PLN"},"status":"COMPLETED"},"metadata":{"order_id":"o"}}}', null, 422, 'validation_failed'],
]);

it('keeps the secrets of providers separate', function () {
    $this->postSignedWebhook($this->examplePayload(), secret: 'acme_secret', provider: 'generic', headers: ['X-Payment-Provider' => 'generic'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_signature');
});
