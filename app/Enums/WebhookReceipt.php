<?php

namespace App\Enums;

/**
 * Outcome of receiving a verified and valid payment webhook.
 */
enum WebhookReceipt: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
}
