<?php

namespace App\Services\Payments\Data;

use InvalidArgumentException;

/**
 * An amount in minor currency units. The currency exponent is always 2
 * (see specs/002-payment-webhook-notifications/research.md, R14).
 */
final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits <= 0) {
            throw new InvalidArgumentException('Money amount must be a positive number of minor units.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Money currency must be a three-letter uppercase ISO 4217 code.');
        }
    }
}
