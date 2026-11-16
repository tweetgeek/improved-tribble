<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Builds and sends payment webhooks signed as described in
 * specs/002-payment-webhook-notifications/contracts/webhook-signature.md.
 */
trait SignsWebhooks
{
    /**
     * @return array<string, string>
     */
    protected function signedWebhookHeaders(
        string $rawBody,
        ?int $timestamp = null,
        ?string $secret = null,
        string $provider = 'generic',
    ): array {
        $timestamp ??= now()->getTimestamp();
        $secret ??= (string) config("webhooks.providers.{$provider}.secret");

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Payment-Provider' => $provider,
            'X-Webhook-Signature' => self::webhookSignature($rawBody, $timestamp, $secret),
        ];
    }

    protected static function webhookSignature(string $rawBody, int $timestamp, string $secret): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @param  array<string, mixed>|string  $payload  An array is JSON-encoded; a string is sent as-is.
     * @param  array<string, string|null>  $headers  Overrides; a null value removes the header.
     */
    protected function postSignedWebhook(
        array|string $payload,
        ?int $timestamp = null,
        ?string $secret = null,
        string $provider = 'generic',
        array $headers = [],
    ): TestResponse {
        $rawBody = is_string($payload) ? $payload : (string) json_encode($payload);

        return $this->postRawWebhook(
            $rawBody,
            array_merge($this->signedWebhookHeaders($rawBody, $timestamp, $secret, $provider), $headers),
        );
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    protected function postRawWebhook(string $rawBody, array $headers): TestResponse
    {
        $server = [];

        foreach (array_filter($headers, fn (?string $value): bool => $value !== null) as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/payment', [], [], [], $server, $rawBody);
    }

    /**
     * The payload from payment_payload_example.json, recursively merged with the overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function examplePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'charge.successful',
            'event_id' => 'evt_9f8d7c6b5a4321',
            'timestamp' => 1718029200,
            'livemode' => true,
            'data' => [
                'transaction' => [
                    'id' => 'txn_8a7b6c5d4e3f',
                    'provider_reference' => 'PAYU-9876543210',
                    'amount' => ['value' => 29990, 'currency' => 'PLN'],
                    'payment_method' => ['type' => 'blik', 'issuer' => 'mBank'],
                    'status' => 'COMPLETED',
                ],
                'customer' => [
                    'id' => 'cus_11223344',
                    'email' => 'jan.kowalski@example.com',
                    'billing_address' => [
                        'first_name' => 'Jan',
                        'last_name' => 'Kowalski',
                        'company' => 'Kowalski Dev Ltd',
                        'country' => 'PL',
                    ],
                ],
                'metadata' => [
                    'order_id' => 'ORD-2024-8839',
                    'store_id' => 'store_main_pl',
                    'channel' => 'mobile_app',
                ],
            ],
        ], $overrides);
    }
}
