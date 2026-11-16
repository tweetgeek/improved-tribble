<?php

use App\Services\Health\Checks\MailTransportCheck;
use App\Services\Health\ComponentState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeSmtpTransport;

beforeEach(function () {
    config(['mail.mailers.smtp.username' => 'mail-user']);
});

it('is up when the SMTP handshake succeeds, and never sends a message', function () {
    $transport = new FakeSmtpTransport();
    Mail::extend('smtp', fn (): FakeSmtpTransport => $transport);

    $status = app(MailTransportCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Up)
        ->and($transport->starts)->toBe(1)
        ->and($transport->stops)->toBe(1)
        ->and($transport->sends)->toBe(0);
});

it('is down with a safe reason over HTTP and details in the CLI when authentication fails', function () {
    Log::spy();
    Mail::extend('smtp', fn (): FakeSmtpTransport => new FakeSmtpTransport(
        new TransportException('Expected response code "235" but got code "535", with message "535 Authentication failed".', 535),
    ));

    $http = app(MailTransportCheck::class)->check(false);
    $cli = app(MailTransportCheck::class)->check(true);

    expect($http->state)->toBe(ComponentState::Down)
        ->and($http->reason)->toBe('SMTP authentication failed')
        ->and($cli->reason)->toContain('SMTP authentication failed')
        ->and($cli->reason)->toContain('TransportException')
        ->and($cli->reason)->toContain('535 Authentication failed');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => ($context['component'] ?? null) === 'mail'
            && ($context['exception'] ?? null) instanceof TransportException,
    )->atLeast()->once();
});

it('is down with a connection reason for other transport failures', function () {
    Log::spy();
    Mail::extend('smtp', fn (): FakeSmtpTransport => new FakeSmtpTransport(
        new TransportException('Connection could not be established'),
    ));

    $status = app(MailTransportCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Down)
        ->and($status->reason)->toBe('SMTP connection failed');
});

it('is down without connecting when no credentials are configured', function () {
    config(['mail.mailers.smtp.username' => '']);
    $transport = new FakeSmtpTransport();
    Mail::extend('smtp', fn (): FakeSmtpTransport => $transport);

    $status = app(MailTransportCheck::class)->check(false);

    expect($status->state)->toBe(ComponentState::Down)
        ->and($status->reason)->toBe('Mail credentials not configured')
        ->and($transport->starts)->toBe(0);
});

it('caches the result for 60 seconds over HTTP but always checks afresh in detailed mode', function () {
    $transport = new FakeSmtpTransport();
    Mail::extend('smtp', fn (): FakeSmtpTransport => $transport);

    app(MailTransportCheck::class)->check(false);
    app(MailTransportCheck::class)->check(false);

    expect($transport->starts)->toBe(1);

    app(MailTransportCheck::class)->check(true);

    expect($transport->starts)->toBe(2);
});
