<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use Psr\Log\LogLevel;

/**
 * The throttle is holding a request until the API's quota resets.
 */
final readonly class RateLimitWaiting implements Event
{
    public function __construct(
        public string $origin,
        public float $wait,
    ) {}

    public function level(): string
    {
        return LogLevel::WARNING;
    }

    public function message(): string
    {
        return sprintf('Rate limit spent for %s; waiting %.1fs for it to reset', $this->origin, $this->wait);
    }

    public function context(): array
    {
        return ['origin' => $this->origin, 'wait' => $this->wait];
    }
}
