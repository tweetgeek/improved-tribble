<?php

use Illuminate\Support\Facades\Route;
use Tests\Support\LogCapture;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::post('/_test/boom', function () {
        throw new RuntimeException('database password leaked here');
    });

    Route::post('/_test/validate', function (Illuminate\Http\Request $request) {
        $request->validate(['event_id' => 'required']);

        return response()->json(['ok' => true]);
    });

    Route::post('/_test/unavailable', fn () => abort(503, 'Down for maintenance'));
    Route::post('/_test/forbidden', fn () => abort(403));
});

it('renders an unhandled exception as a generic 500 and logs it with the request', function () {
    $handler = LogCapture::start();

    $response = $this->postJson('/_test/boom', ['event_id' => 'evt_1', 'password' => 's3cret'], ['X-Correlation-ID' => 'ef-500'])
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.correlation_id', 'ef-500');

    $errors = LogCapture::errors($handler);

    expect($response->getContent())->not->toContain('database password leaked here')
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]->context['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($errors[0]->extra['correlation_id'])->toBe('ef-500')
        ->and($errors[0]->context['request']['method'])->toBe('POST')
        ->and($errors[0]->context['request']['path'])->toBe('_test/boom')
        ->and($errors[0]->context['request']['body']['event_id'])->toBe('evt_1')
        ->and($errors[0]->context['request']['body']['password'])->toBe('[REDACTED]');
});

it('shows the exception message of a 500 only in debug mode', function () {
    config(['app.debug' => true]);

    $this->postJson('/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.message', 'database password leaked here');
});

it('renders validation errors as 422 with details and logs them', function () {
    $handler = LogCapture::start();

    $this->postJson('/_test/validate', ['other' => 'x'], ['X-Correlation-ID' => 'ef-422'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.correlation_id', 'ef-422')
        ->assertJsonStructure(['error' => ['details' => ['event_id']]]);

    $errors = LogCapture::errors($handler);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->context['exception'])->toBeInstanceOf(Illuminate\Validation\ValidationException::class)
        ->and($errors[0]->context['request']['body'])->toBe(['other' => 'x']);
});

it('renders an unknown route as 404 without logging an error', function () {
    $handler = LogCapture::start();

    $this->get('/_test/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertHeader('X-Correlation-ID');

    expect(LogCapture::errors($handler))->toBeEmpty();
});

it('renders a wrong method as 405 without logging an error', function () {
    $handler = LogCapture::start();

    $this->get('/_test/boom')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');

    expect(LogCapture::errors($handler))->toBeEmpty();
});

it('renders a 503 as service_unavailable and logs it', function () {
    $handler = LogCapture::start();

    $this->postJson('/_test/unavailable')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'service_unavailable');

    expect(LogCapture::errors($handler))->not->toBeEmpty();
});

it('keeps client errors below 500', function () {
    $this->postJson('/_test/forbidden')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('renders JSON even when the client does not ask for it', function () {
    $this->post('/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error');
});
