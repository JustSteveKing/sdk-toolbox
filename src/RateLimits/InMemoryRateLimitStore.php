<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

/**
 * Per process. Enough for a CLI command or a worker; use the PSR-16 store
 * when several processes share one quota.
 */
final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, float> */
    private array $until = [];

    public function exhaustedUntil(string $key): ?float
    {
        return $this->until[$key] ?? null;
    }

    public function exhaust(string $key, float $until): void
    {
        $this->until[$key] = $until;
    }

    public function clear(string $key): void
    {
        unset($this->until[$key]);
    }
}
