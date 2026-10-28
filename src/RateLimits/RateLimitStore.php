<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

/**
 * Where a Throttle remembers when a quota ran out.
 */
interface RateLimitStore
{
    /**
     * The Unix time the quota for this key resets, if it's known to be spent.
     */
    public function exhaustedUntil(string $key): ?float;

    public function exhaust(string $key, float $until): void;

    public function clear(string $key): void;
}
