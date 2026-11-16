<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookReceipt;
use App\Events\PaymentEventReceived;
use App\Models\WebhookEvent;
use App\Services\Payments\Data\PaymentEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores a verified, valid webhook event and publishes it for the notification system, exactly
 * once per (provider, event_id): the unique index decides between concurrent deliveries and
 * createOrFirst() uses a savepoint, so a lost race does not abort the transaction (research R6).
 *
 * The event is dispatched INSIDE the transaction (the queued listener is pushed before the
 * commit). If pushing fails, the stored row is rolled back and the provider's retry is accepted
 * as new; if the commit fails after the push, the provider's retry is stored again and channel
 * idempotency prevents a second notification (research R7).
 */
final class ReceivePaymentWebhook
{
    /**
     * @param  array<string, mixed>  $payload  The complete decoded body, unknown fields included.
     */
    public function handle(PaymentEvent $event, array $payload, string $rawBody): WebhookReceipt
    {
        $payloadSha256 = hash('sha256', $rawBody);

        return DB::transaction(function () use ($event, $payload, $payloadSha256): WebhookReceipt {
            $stored = WebhookEvent::createOrFirst(
                ['provider' => $event->provider, 'event_id' => $event->eventId],
                [
                    'event_type' => $event->type,
                    'livemode' => $event->livemode,
                    'occurred_at' => $event->occurredAt,
                    'payload' => $payload,
                    'payload_sha256' => $payloadSha256,
                    'correlation_id' => (string) Context::get('correlation_id'),
                    'received_at' => now(),
                ],
            );

            if (! $stored->wasRecentlyCreated) {
                $this->reportDuplicate($stored, $payloadSha256);

                return WebhookReceipt::Duplicate;
            }

            PaymentEventReceived::dispatch($event);

            return WebhookReceipt::Accepted;
        });
    }

    private function reportDuplicate(WebhookEvent $stored, string $receivedSha256): void
    {
        if (! hash_equals($stored->payload_sha256, $receivedSha256)) {
            Log::warning('webhook.duplicate_payload_mismatch', [
                'provider' => $stored->provider,
                'event_id' => $stored->event_id,
                'stored_sha256' => $stored->payload_sha256,
                'received_sha256' => $receivedSha256,
            ]);
        }

        Log::info('webhook.duplicate', [
            'provider' => $stored->provider,
            'event_id' => $stored->event_id,
        ]);
    }
}
