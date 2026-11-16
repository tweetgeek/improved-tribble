<?php

namespace Tests\Support\Notifications;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * A mail transport that fails every send with the given exception.
 */
final class ThrowingTransport implements TransportInterface
{
    public int $sends = 0;

    public function __construct(private readonly Throwable $failure)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sends++;

        throw $this->failure;
    }

    public function __toString(): string
    {
        return 'throwing://';
    }
}
