<?php

use App\Mail\PaymentEventMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Monolog\LogRecord;
use Tests\Support\LogCapture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'webhooks.providers.generic.secret' => 'whsec_test_secret',
        'notifications.channels.salesteam.mock.mode' => 'success',
    ]);
    Mail::fake();
    $this->logs = LogCapture::start();
});

function salesTeamDeliveries(Monolog\Handler\TestHandler $handler): int
{
    return count(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === 'salesteam.mock.received'));
}

it('notifies the sales team on every channel exactly once per payment event', function () {
    $this->postSignedWebhook($this->examplePayload(), headers: ['X-Correlation-ID' => 'flow-1'])
        ->assertOk()
        ->assertJsonPath('status', 'accepted');

    Mail::assertSent(PaymentEventMail::class, 1);
    expect(salesTeamDeliveries($this->logs))->toBe(1);

    $this->postSignedWebhook($this->examplePayload())
        ->assertOk()
        ->assertJsonPath('status', 'duplicate');

    Mail::assertSent(PaymentEventMail::class, 1);
    expect(salesTeamDeliveries($this->logs))->toBe(1);
});
