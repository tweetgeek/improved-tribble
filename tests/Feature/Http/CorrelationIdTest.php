<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    Route::get('/_test/correlation', function () {
        Log::channel('correlation_test')->info('Handling test request');

        return response()->json(['correlation_id' => Context::get('correlation_id')]);
    });
});

it('generates a UUIDv7 correlation id when the header is missing', function () {
    $response = $this->getJson('/_test/correlation');

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->not->toBeNull()
        ->and(Str::isUuid($correlationId, 7))->toBeTrue();
});

it('reuses a valid correlation id provided by the client', function () {
    $this->getJson('/_test/correlation', ['X-Correlation-ID' => 'abc-123.x:y_z'])
        ->assertHeader('X-Correlation-ID', 'abc-123.x:y_z')
        ->assertJson(['correlation_id' => 'abc-123.x:y_z']);
});

it('replaces an invalid correlation id with a generated one', function (string $invalidCorrelationId) {
    $response = $this->getJson('/_test/correlation', ['X-Correlation-ID' => $invalidCorrelationId]);

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->not->toBe($invalidCorrelationId)
        ->and(Str::isUuid($correlationId, 7))->toBeTrue();
})->with([
    'too long' => str_repeat('a', 129),
    'whitespace and newline' => "a b\n",
    'injection attempt' => '"}{"level":"debug',
]);

it('attaches the correlation id to log records written during the request', function () {
    $handler = new TestHandler();
    config(['logging.channels.correlation_test' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
    ]]);
    $this->app->bind(TestHandler::class, fn (): TestHandler => $handler);

    $this->getJson('/_test/correlation', ['X-Correlation-ID' => 'log-check-1']);

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->extra['correlation_id'] ?? null)->toBe('log-check-1');
});
