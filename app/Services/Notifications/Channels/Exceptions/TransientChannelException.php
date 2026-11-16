<?php

namespace App\Services\Notifications\Channels\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A temporary channel problem (unavailable, timeout, 5xx, rate limit): the job is retried.
 */
final class TransientChannelException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
