<?php

namespace App\Services\Payments\Data;

final readonly class CustomerDetails
{
    /**
     * @param  string|null  $country  ISO 3166-1 alpha-2 code.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $company = null,
        public ?string $country = null,
    ) {
    }
}
