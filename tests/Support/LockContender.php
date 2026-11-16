<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Builds closures that can be serialized to child processes. A closure created inside a Pest test
 * is scoped to a generated test class that the child process cannot autoload.
 */
final class LockContender
{
    /**
     * @return Closure(): bool
     */
    public static function attempt(string $key, int $seconds): Closure
    {
        return static fn (): bool => Cache::store('redis')->lock($key, $seconds)->get();
    }
}
