<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'generic',
            'event_id' => 'evt_'.Str::lower(Str::random(14)),
            'channel' => fake()->randomElement(['mailtrap', 'salesteam']),
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'last_error' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'correlation_id' => (string) Str::uuid7(),
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Delivered,
            'attempts' => 1,
            'delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'attempts' => 4,
            'last_error' => 'RuntimeException: channel unavailable',
            'failed_at' => now(),
        ]);
    }
}
