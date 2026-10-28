<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

/**
 * One rate-limit policy as a response reported it.
 *
 * Remaining quota is a hint, never a promise: the draft says a client MUST
 * NOT assume a positive quota guarantees the next request will be served
 * (draft-ietf-httpapi-ratelimit-headers-11 section 4.1.1).
 */
final readonly class RateLimit
{
    /**
     * @param  string  $policy  the policy's name, `default` when the API doesn't name one
     * @param  int|null  $limit  the quota for the window, when known
     * @param  float|null  $resetIn  seconds until the quota resets, when known
     * @param  int|null  $window  the policy's window in seconds, when known
     */
    public function __construct(
        public string $policy,
        public int $remaining,
        public ?int $limit = null,
        public ?float $resetIn = null,
        public ?int $window = null,
        public RateLimitSource $source = RateLimitSource::Ietf,
    ) {}

    public function exhausted(): bool
    {
        return $this->remaining <= 0;
    }
}
