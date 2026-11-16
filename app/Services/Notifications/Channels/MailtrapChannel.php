<?php

namespace App\Services\Notifications\Channels;

use App\Mail\PaymentEventMail;
use App\Services\Notifications\Channels\Contracts\NotificationChannel;
use App\Services\Notifications\Channels\Exceptions\PermanentChannelException;
use App\Services\Notifications\Channels\Exceptions\TransientChannelException;
use App\Services\Payments\Data\PaymentNotification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * E-mail to the sales team through the Mailtrap sandbox (the only allowed mail transport).
 *
 * Missing recipients or SMTP credentials and SMTP authentication failures (530/535) are
 * permanent; any other transport error (no connection, timeout, 4xx, the sandbox rate limit
 * "550 too many emails per second") is transient (research R11).
 */
final class MailtrapChannel implements NotificationChannel
{
    private const AUTHENTICATION_FAILURE_CODES = [530, 535];

    public function name(): string
    {
        return 'mailtrap';
    }

    public function send(PaymentNotification $notification): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            throw new PermanentChannelException('Mailtrap channel: no sales team recipients are configured.');
        }

        if ($this->usesSmtpWithoutCredentials()) {
            throw new PermanentChannelException('Mailtrap channel: SMTP credentials (MAIL_USERNAME, MAIL_PASSWORD) are not configured.');
        }

        try {
            Mail::to($recipients)->send(new PaymentEventMail($notification));
        } catch (TransportExceptionInterface $exception) {
            throw in_array($exception->getCode(), self::AUTHENTICATION_FAILURE_CODES, true)
                ? new PermanentChannelException('Mailtrap channel: SMTP authentication failed.', $exception)
                : new TransientChannelException('Mailtrap channel: SMTP transport failed.', $exception);
        }
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_values(array_filter((array) config('notifications.channels.mailtrap.recipients', [])));
    }

    private function usesSmtpWithoutCredentials(): bool
    {
        return config('mail.default') === 'smtp'
            && (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password')));
    }
}
