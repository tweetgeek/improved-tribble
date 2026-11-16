<?php

use App\Services\Webhooks\Exceptions\InvalidWebhookSignature;
use App\Services\Webhooks\Signatures\HmacSignatureVerifier;
use App\Services\Webhooks\Signatures\SignatureVerification;
use Carbon\CarbonImmutable;

/**
 * Test vector from contracts/webhook-signature.md.
 *
 * @return array{secret: string, timestamp: int, body: string, signature: string}
 */
function hmacTestVector(): array
{
    return [
        'secret' => 'whsec_test_secret',
        'timestamp' => 1718029200,
        'body' => '{"event":"charge.successful","event_id":"evt_test_1"}',
        'signature' => 'b8e80ecc138a0aedc617c4ce00d848da10f829a787bb7f160c7111497c6d8ed5',
    ];
}

function signHmac(string $body, int $timestamp, string $secret): string
{
    return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

function expectSignatureRejected(Closure $verification, string $reason): void
{
    expect($verification)->toThrow(
        fn (InvalidWebhookSignature $exception) => expect($exception->reason)->toBe($reason),
    );
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(hmacTestVector()['timestamp']));
    $this->verifier = new HmacSignatureVerifier();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('accepts the contract test vector signed with the current secret', function () {
    ['secret' => $secret, 'timestamp' => $t, 'body' => $body, 'signature' => $signature] = hmacTestVector();

    expect(signHmac($body, $t, $secret))->toBe($signature);

    $result = $this->verifier->verify("t={$t},v1={$signature}", $body, $secret, null, 300);

    expect($result)->toBeInstanceOf(SignatureVerification::class)
        ->and($result->verifiedWith)->toBe(SignatureVerification::CURRENT);
});

it('accepts a timestamp exactly at the edge of the tolerance window', function (int $offset) {
    ['secret' => $secret, 'timestamp' => $t, 'body' => $body] = hmacTestVector();
    $signedAt = $t + $offset;

    $result = $this->verifier->verify("t={$signedAt},v1=".signHmac($body, $signedAt, $secret), $body, $secret, null, 300);

    expect($result->verifiedWith)->toBe(SignatureVerification::CURRENT);
})->with(['300 s in the past' => -300, '300 s in the future' => 300]);

it('rejects a timestamp outside the tolerance window as expired', function (int $offset) {
    ['secret' => $secret, 'timestamp' => $t, 'body' => $body] = hmacTestVector();
    $signedAt = $t + $offset;

    expectSignatureRejected(
        fn () => $this->verifier->verify("t={$signedAt},v1=".signHmac($body, $signedAt, $secret), $body, $secret, null, 300),
        'signature_expired',
    );
})->with(['301 s in the past' => -301, '301 s in the future' => 301]);

it('rejects a missing or malformed signature header', function (?string $header) {
    ['secret' => $secret, 'body' => $body] = hmacTestVector();

    expectSignatureRejected(fn () => $this->verifier->verify($header, $body, $secret, null, 300), 'missing_signature');
})->with([
    'no header' => null,
    'empty header' => '',
    'no timestamp' => 'v1=abc',
    'no signature' => 't=1718029200',
    'non numeric timestamp' => 't=yesterday,v1=abc',
    'three signatures' => 't=1718029200,v1=a,v1=b,v1=c',
]);

it('rejects a signature that does not match any secret', function () {
    ['secret' => $secret, 'timestamp' => $t, 'body' => $body] = hmacTestVector();

    expectSignatureRejected(
        fn () => $this->verifier->verify("t={$t},v1=".signHmac($body, $t, 'wrong_secret'), $body, $secret, null, 300),
        'invalid_signature',
    );
});

it('rejects a valid signature when the body was modified', function () {
    ['secret' => $secret, 'timestamp' => $t, 'body' => $body, 'signature' => $signature] = hmacTestVector();

    expectSignatureRejected(
        fn () => $this->verifier->verify("t={$t},v1={$signature}", $body.' ', $secret, null, 300),
        'invalid_signature',
    );
});

it('accepts the previous secret during rotation and reports it', function () {
    ['timestamp' => $t, 'body' => $body] = hmacTestVector();

    $result = $this->verifier->verify("t={$t},v1=".signHmac($body, $t, 'old_secret'), $body, 'new_secret', 'old_secret', 300);

    expect($result->verifiedWith)->toBe(SignatureVerification::PREVIOUS);
});

it('rejects the old secret once the previous secret is removed', function (?string $previousSecret) {
    ['timestamp' => $t, 'body' => $body] = hmacTestVector();

    expectSignatureRejected(
        fn () => $this->verifier->verify("t={$t},v1=".signHmac($body, $t, 'old_secret'), $body, 'new_secret', $previousSecret, 300),
        'invalid_signature',
    );
})->with(['null' => null, 'empty' => '']);

it('accepts two signatures sent during rotation when one of them matches', function () {
    ['timestamp' => $t, 'body' => $body] = hmacTestVector();
    $header = "t={$t},v1=".signHmac($body, $t, 'old_secret').',v1='.signHmac($body, $t, 'new_secret');

    expect($this->verifier->verify($header, $body, 'new_secret', null, 300)->verifiedWith)
        ->toBe(SignatureVerification::CURRENT);
});
