<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class FakeSmtpTransport extends SmtpTransport
{
    public int $starts = 0;

    public int $stops = 0;

    public int $sends = 0;

    public function __construct(private readonly ?Throwable $startFailure = null)
    {
        parent::__construct();
    }

    public function start(): void
    {
        $this->starts++;

        if ($this->startFailure !== null) {
            throw $this->startFailure;
        }
    }

    public function stop(): void
    {
        $this->stops++;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sends++;

        return null;
    }
}
