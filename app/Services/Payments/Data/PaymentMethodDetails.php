<?php

namespace App\Services\Payments\Data;

final readonly class PaymentMethodDetails
{
    public function __construct(
        public ?string $type = null,
        public ?string $issuer = null,
    ) {
    }
}
