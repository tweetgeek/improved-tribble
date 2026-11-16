<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state, shaped like payment_payload_example.json.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::lower(Str::random(14));
        $payload = [
            'event' => 'charge.successful',
            'event_id' => $eventId,
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_'.Str::lower(Str::random(12)),
                    'amount' => ['value' => fake()->numberBetween(100, 100000), 'currency' => 'PLN'],
                    'status' => 'COMPLETED',
                ],
                'metadata' => ['order_id' => 'ORD-'.fake()->numerify('####-####')],
            ],
        ];

        return [
            'provider' => 'generic',
            'event_id' => $eventId,
            'event_type' => 'charge.successful',
            'livemode' => true,
            'occurred_at' => now()->subMinute(),
            'payload' => $payload,
            'payload_sha256' => hash('sha256', (string) json_encode($payload)),
            'correlation_id' => (string) Str::uuid7(),
            'received_at' => now(),
        ];
    }

    /**
     * A test-mode event ("livemode": false).
     */
    public function test(): static
    {
        return $this->state(fn (array $attributes): array => [
            'livemode' => false,
            'payload' => array_replace($attributes['payload'], ['livemode' => false]),
        ]);
    }
}
