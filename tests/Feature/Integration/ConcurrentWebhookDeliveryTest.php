<?php

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Webhooks\WebhookContender;

pest()->group('integration');

beforeEach(function () {
    $this->database = env('INTEGRATION_DB_DATABASE', 'payment_gateway');

    config(['database.connections.pgsql.database' => $this->database]);
    $this->eventIds = [];
});

afterEach(function () {
    (new WebhookEvent())->setConnection('pgsql')->newQuery()->whereIn('event_id', $this->eventIds)->delete();
});

it('accepts exactly one of 10 concurrent deliveries of the same event', function (int $round) {
    $eventId = 'evt_it_'.Str::lower(Str::random(12));
    $this->eventIds[] = $eventId;

    $payload = $this->examplePayload(['event_id' => $eventId]);
    $rawBody = (string) json_encode($payload);

    $results = Concurrency::driver('process')->run(
        array_fill(0, 10, WebhookContender::receive($payload, $rawBody, $this->database)),
    );

    expect(array_count_values($results))->toBe(['accepted' => 1, 'duplicate' => 9])
        ->and(DB::connection('pgsql')->table('webhook_events')->where('event_id', $eventId)->count())->toBe(1);
})->with(range(1, 5));
