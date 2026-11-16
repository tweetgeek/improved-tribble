<?php

use App\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $extra
 */
function makeLogRecord(array $context = [], array $extra = [], string $message = 'Something happened'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable(),
        channel: 'testing',
        level: Level::Error,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

function makeRedactor(int $maxStringLength = 10000): RedactSensitiveData
{
    return new RedactSensitiveData(
        keys: ['password', 'secret', 'token', 'apikey', 'authorization', 'signature', 'cardnumber', 'pan', 'cvv', 'cvc', 'cookie', 'iban'],
        replacement: '[REDACTED]',
        maxStringLength: $maxStringLength,
    );
}

it('redacts sensitive keys at any depth of context and extra', function () {
    $record = makeLogRecord(
        context: [
            'password' => 'hunter2',
            'request' => [
                'headers' => [
                    'X-Api-Key' => ['key-123'],
                    'stripe-signature' => 't=1,v1=abc',
                    'Authorization' => 'Bearer xyz',
                ],
                'body' => ['card_number' => '4242424242424242', 'event_id' => 'evt_1'],
            ],
        ],
        extra: ['correlation_id' => 'abc', 'nested' => ['client_secret' => 's3cret']],
    );

    $processed = makeRedactor()($record);

    expect($processed->context['password'])->toBe('[REDACTED]')
        ->and($processed->context['request']['headers']['X-Api-Key'])->toBe('[REDACTED]')
        ->and($processed->context['request']['headers']['stripe-signature'])->toBe('[REDACTED]')
        ->and($processed->context['request']['headers']['Authorization'])->toBe('[REDACTED]')
        ->and($processed->context['request']['body']['card_number'])->toBe('[REDACTED]')
        ->and($processed->extra['nested']['client_secret'])->toBe('[REDACTED]');
});

it('leaves non-sensitive keys untouched', function () {
    $record = makeLogRecord(context: ['event_id' => 'evt_1', 'amount' => 1999, 'items' => ['sku' => 'A1']]);

    $processed = makeRedactor()($record);

    expect($processed->context)->toBe(['event_id' => 'evt_1', 'amount' => 1999, 'items' => ['sku' => 'A1']]);
});

it('truncates long strings and annotates the number of removed characters', function () {
    $record = makeLogRecord(context: ['body' => str_repeat('a', 10050)]);

    $processed = makeRedactor()($record);

    expect($processed->context['body'])->toBe(str_repeat('a', 10000).'…[truncated 50 chars]');
});

it('does not truncate strings at the limit', function () {
    $record = makeLogRecord(context: ['body' => str_repeat('a', 10000)]);

    expect(makeRedactor()($record)->context['body'])->toBe(str_repeat('a', 10000));
});

it('masks sensitive pairs inside strings that look like JSON, even when the JSON is broken', function () {
    $record = makeLogRecord(context: [
        'body' => '{"event_id": "evt_1", "password": "s3cret", "X-Api-Key":"key-1","card_number": 4242424242424242, "note": "ok", "token": "unterminated',
    ]);

    $body = makeRedactor()($record)->context['body'];

    expect($body)->toBe('{"event_id": "evt_1", "password": "[REDACTED]", "X-Api-Key":"[REDACTED]","card_number": [REDACTED], "note": "ok", "token": "[REDACTED]');
});

it('does not change message or level', function () {
    $record = makeLogRecord(context: ['password' => 'x'], message: 'password reset failed');

    $processed = makeRedactor()($record);

    expect($processed->message)->toBe('password reset failed')
        ->and($processed->level)->toBe(Level::Error);
});

it('masks the payment webhook signature header but keeps the provider and the verified secret kind', function () {
    $record = makeRedactor()(makeLogRecord(context: [
        'headers' => [
            'x-webhook-signature' => ['t=1718029200,v1=b8e80ecc138a0aedc617c4ce00d848da10f829a787bb7f160c7111497c6d8ed5'],
            'x-payment-provider' => ['generic'],
        ],
        'verified_with' => 'previous',
    ]));

    expect($record->context['headers']['x-webhook-signature'])->toBe('[REDACTED]')
        ->and($record->context['headers']['x-payment-provider'])->toBe(['generic'])
        ->and($record->context['verified_with'])->toBe('previous');
});
