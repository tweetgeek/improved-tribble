<?php

use App\Mail\PaymentEventMail;
use App\Services\Notifications\Channels\MailtrapChannel;
use App\Services\Payments\PaymentNotificationFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Notifications\PaymentEvents;

beforeEach(function () {
    config(['notifications.channels.mailtrap.recipients' => ['sales@example.test', 'boss@example.test']]);
    $this->notification = app(PaymentNotificationFormatter::class)->format(PaymentEvents::example());
});

it('sends the payment mail to every configured sales team address', function () {
    Mail::fake();

    app(MailtrapChannel::class)->send($this->notification);

    Mail::assertSent(PaymentEventMail::class, fn (PaymentEventMail $mail): bool => $mail->hasTo('sales@example.test')
        && $mail->hasTo('boss@example.test')
        && $mail->hasSubject('Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN'));
    Mail::assertSentCount(1);
});

it('is sent synchronously inside the channel job, not queued again', function () {
    expect(PaymentEventMail::class)->not->toImplement(ShouldQueue::class)
        ->and(app(MailtrapChannel::class)->name())->toBe('mailtrap');
});

it('renders all notification fields in Polish in both HTML and text versions', function () {
    $mail = new PaymentEventMail($this->notification);

    $mail->assertSeeInHtml('299,90 PLN')
        ->assertSeeInText('299,90 PLN')
        ->assertSeeInHtml('10.06.2024 16:20 CEST')
        ->assertSeeInText('10.06.2024 16:20 CEST');

    foreach ($this->notification->fields as $field) {
        $mail->assertSeeInHtml($field['label'])->assertSeeInText($field['label'].': '.$field['value'], false);
    }
});

it('highlights test events in the mail', function () {
    $mail = new PaymentEventMail(app(PaymentNotificationFormatter::class)->format(PaymentEvents::example(['livemode' => false])));

    $mail->assertSeeInHtml('ZDARZENIE TESTOWE')->assertHasSubject('[TEST] Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN');
});

it('carries the correlation id in a mail header', function () {
    Context::add('correlation_id', 'mail-corr-1');

    expect((new PaymentEventMail($this->notification))->headers()->text)
        ->toMatchArray(['X-Correlation-ID' => 'mail-corr-1']);
});

function useThrowingMailer(Throwable $failure): Tests\Support\Notifications\ThrowingTransport
{
    $transport = new Tests\Support\Notifications\ThrowingTransport($failure);

    Mail::extend('throwing', fn () => $transport);
    config([
        'mail.default' => 'throwing',
        'mail.mailers.throwing' => ['transport' => 'throwing'],
    ]);

    return $transport;
}

it('maps SMTP authentication failures to a permanent error', function (int $code) {
    useThrowingMailer(new Symfony\Component\Mailer\Exception\UnexpectedResponseException('Authentication failed', $code));

    expect(fn () => app(MailtrapChannel::class)->send($this->notification))->toThrow(
        fn (App\Services\Notifications\Channels\Exceptions\PermanentChannelException $e) => expect($e->getPrevious())
            ->toBeInstanceOf(Symfony\Component\Mailer\Exception\UnexpectedResponseException::class),
    );
})->with([530, 535]);

it('maps connection problems, timeouts and rate limits to a transient error', function (Throwable $failure) {
    useThrowingMailer($failure);

    expect(fn () => app(MailtrapChannel::class)->send($this->notification))->toThrow(
        fn (App\Services\Notifications\Channels\Exceptions\TransientChannelException $e) => expect($e->getPrevious())->toBe($failure),
    );
})->with([
    'no connection' => fn () => new Symfony\Component\Mailer\Exception\TransportException('Connection could not be established with host "sandbox.smtp.mailtrap.io:2525"'),
    'service unavailable 421' => fn () => new Symfony\Component\Mailer\Exception\UnexpectedResponseException('Service not available', 421),
    'mailbox busy 450' => fn () => new Symfony\Component\Mailer\Exception\UnexpectedResponseException('Mailbox unavailable', 450),
    'sandbox rate limit 550' => fn () => new Symfony\Component\Mailer\Exception\UnexpectedResponseException('5.7.0 Too many emails per second', 550),
]);

it('fails permanently without sending when the configuration is incomplete', function (array $config) {
    $transport = useThrowingMailer(new RuntimeException('must not be called'));
    config($config);

    expect(fn () => app(MailtrapChannel::class)->send($this->notification))
        ->toThrow(App\Services\Notifications\Channels\Exceptions\PermanentChannelException::class)
        ->and($transport->sends)->toBe(0);
})->with([
    'no recipients' => [['notifications.channels.mailtrap.recipients' => []]],
    'missing smtp username' => [['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => 'x']],
    'missing smtp password' => [['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'x', 'mail.mailers.smtp.password' => '']],
]);
