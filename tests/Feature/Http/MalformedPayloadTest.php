<?php

use Tests\Support\LogCapture;

/**
 * @return Illuminate\Testing\TestResponse
 */
function postRawJson(string $content, string $correlationId = 'mp-test-1')
{
    return test()->call('POST', '/', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CORRELATION_ID' => $correlationId,
    ], $content);
}

it('rejects malformed JSON with 400 in the common error format', function () {
    postRawJson('{"event_id": "evt_1",')
        ->assertStatus(400)
        ->assertJson([
            'error' => [
                'code' => 'malformed_payload',
                'correlation_id' => 'mp-test-1',
            ],
        ])
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']])
        ->assertHeader('X-Correlation-ID', 'mp-test-1');
});

it('logs the rejected request with method, path, headers and the raw body', function () {
    $handler = LogCapture::start();

    postRawJson('{"event_id": "evt_1",');

    $errors = LogCapture::errors($handler);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->context['method'])->toBe('POST')
        ->and($errors[0]->context['path'])->toBe('/')
        ->and($errors[0]->context['headers'])->toBeArray()->not->toBeEmpty()
        ->and($errors[0]->context['body'])->toBe('{"event_id": "evt_1",')
        ->and($errors[0]->extra['correlation_id'])->toBe('mp-test-1');
});

it('masks secrets inside the logged raw body of a malformed request', function () {
    $handler = LogCapture::start();

    postRawJson('{"event_id": "evt_1", "password": "s3cret", "card_number": 4242424242424242, "note": "ok",');

    $logged = json_encode(LogCapture::errors($handler)[0]->context);

    expect($logged)->not->toContain('s3cret')
        ->and($logged)->not->toContain('4242424242424242')
        ->and($logged)->toContain('[REDACTED]')
        ->and($logged)->toContain('evt_1');
});

it('rejects a body larger than 1 MiB with 413 and logs it', function () {
    $handler = LogCapture::start();

    postRawJson('{"data":"'.str_repeat('a', 1048576).'"}')
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'payload_too_large')
        ->assertJsonPath('error.correlation_id', 'mp-test-1');

    $errors = LogCapture::errors($handler);

    expect($errors)->toHaveCount(1)
        ->and(strlen($errors[0]->context['body']))->toBeLessThan(10100);
});

it('lets valid JSON and empty bodies through to routing', function (string $content) {
    $handler = LogCapture::start();

    $response = postRawJson($content);

    expect($response->getStatusCode())->toBe(405)
        ->and(LogCapture::errors($handler))->toBeEmpty();
})->with([
    'valid json' => '{"event_id": "evt_1"}',
    'empty body' => '',
]);

it('never answers a malformed request with a 5xx', function (string $content) {
    expect(postRawJson($content)->getStatusCode())->toBeLessThan(500);
})->with([
    'truncated' => '{"a": [1, 2',
    'not json' => 'plain text',
    'huge' => str_repeat('{', 1048577),
]);
