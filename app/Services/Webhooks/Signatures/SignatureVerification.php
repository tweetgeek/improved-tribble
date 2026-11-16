<?php

namespace App\Services\Webhooks\Signatures;

/**
 * Result of a successful signature verification: which of the active secrets matched.
 */
final readonly class SignatureVerification
{
    public const CURRENT = 'current';

    public const PREVIOUS = 'previous';

    public function __construct(public string $verifiedWith)
    {
    }
}
