<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Closure;

/**
 * No lock beyond the process itself, which runs one request at a time.
 * Enough with InMemoryTokenStore; with a shared store, use a real lock.
 */
final readonly class ProcessLock implements RefreshLock
{
    public function synchronized(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}
