<?php

namespace App\Services\Notifications\Channels\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A channel error that retrying cannot fix (rejected as invalid, misconfiguration): the job moves to dead-letter at once.
 */
final class PermanentChannelException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
