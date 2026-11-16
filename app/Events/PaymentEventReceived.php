<?php

namespace App\Events;

use App\Services\Payments\Data\PaymentEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched inside the transaction that stores a newly accepted webhook event.
 *
 * It deliberately does NOT implement ShouldDispatchAfterCommit: the queued listener is pushed
 * before the commit, so a queue failure rolls the stored event back and the provider's retry
 * is accepted as new. Listeners and jobs never read webhook_events and receive the complete
 * PaymentEvent, so running before the commit is safe (research R7).
 */
class PaymentEventReceived
{
    use Dispatchable;

    public function __construct(
        public PaymentEvent $paymentEvent,
    ) {
    }
}
