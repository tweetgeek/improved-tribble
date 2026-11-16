<?php

namespace App\Services\Webhooks\Signatures;

use App\Services\Webhooks\Exceptions\InvalidWebhookSignature;
use Carbon\CarbonImmutable;

/**
 * Verifies "t=<unix>,v1=<hex>[,v1=<hex>]" signatures: HMAC-SHA256 of "{t}.{raw body}",
 * a timestamp window against replays and up to two active secrets for rotation
 * (contracts/webhook-signature.md).
 */
final class HmacSignatureVerifier
{
    private const MAX_SIGNATURES = 2;

    /**
     * @throws InvalidWebhookSignature
     */
    public function verify(?string $header, string $rawBody, string $secret, ?string $previousSecret, int $tolerance): SignatureVerification
    {
        [$timestamp, $signatures] = $this->parse($header);

        if (abs(CarbonImmutable::now()->getTimestamp() - $timestamp) > $tolerance) {
            throw InvalidWebhookSignature::expired();
        }

        $signedPayload = $timestamp.'.'.$rawBody;
        $secrets = [SignatureVerification::CURRENT => $secret, SignatureVerification::PREVIOUS => $previousSecret];

        foreach ($secrets as $kind => $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $expected = hash_hmac('sha256', $signedPayload, $candidate);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return new SignatureVerification($kind);
                }
            }
        }

        throw InvalidWebhookSignature::mismatch();
    }

    /**
     * @return array{0: int, 1: list<string>}
     *
     * @throws InvalidWebhookSignature
     */
    private function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            throw InvalidWebhookSignature::missing();
        }

        $timestamps = [];
        $signatures = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            match ($key) {
                't' => $timestamps[] = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if (count($timestamps) !== 1 || ! ctype_digit($timestamps[0])) {
            throw InvalidWebhookSignature::missing();
        }

        if ($signatures === [] || count($signatures) > self::MAX_SIGNATURES) {
            throw InvalidWebhookSignature::missing();
        }

        return [(int) $timestamps[0], $signatures];
    }
}
