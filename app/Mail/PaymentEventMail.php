<?php

namespace App\Mail;

use App\Services\Payments\Data\PaymentNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Context;

/**
 * Payment notification e-mail for the sales team. Deliberately NOT ShouldQueue: it is sent
 * synchronously by SendMailtrapNotification, which already is the queued unit of work.
 */
class PaymentEventMail extends Mailable
{
    public function __construct(public PaymentNotification $notification)
    {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->subject);
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        return new Headers(text: ['X-Correlation-ID' => (string) Context::get('correlation_id', '')]);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(view: 'mail.payment-event', text: 'mail.payment-event-text');
    }
}
