<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

use Psr\SimpleCache\CacheInterface;

/**
 * Shares rate-limit state through any PSR-16 cache, so every process
 * spending the same quota waits for the same reset. Needs psr/simple-cache.
 */
final readonly class Psr16RateLimitStore implements RateLimitStore
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'sdk-toolbox.rate-limit.',
    ) {}

    public function exhaustedUntil(string $key): ?float
    {
        $until = $this->cache->get($this->key($key));

        return is_float($until) || is_int($until) ? (float) $until : null;
    }

    public function exhaust(string $key, float $until): void
    {
        $this->cache->set($this->key($key), $until, max(1, (int) ceil($until - microtime(true))));
    }

    public function clear(string $key): void
    {
        $this->cache->delete($this->key($key));
    }

    /**
     * PSR-16 keys can't contain `{}()/\@:`, so the host goes in hashed.
     */
    private function key(string $key): string
    {
        return $this->prefix.hash('xxh128', $key);
    }
}
