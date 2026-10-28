<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

use JustSteveKing\SdkToolbox\Errors\RateLimitExceededException;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Retries\RetryAfter;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use JustSteveKing\SdkToolbox\Time\SystemSleeper;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stops sending once the API says the quota is spent, until it resets.
 *
 * After every response it reads the rate-limit headers, and a 429's
 * `Retry-After`. When the most restrictive policy has nothing left, the
 * next request to that host waits for the reset, or, if that's longer than
 * `maxWait`, throws RateLimitExceededException without sending. That's
 * the draft's "SHOULD NOT exceed the available quota" (section 7), done
 * for the caller.
 *
 * It only acts on what the server reported. It never assumes quota is
 * left because the headers said so earlier.
 */
final readonly class Throttle implements Middleware
{
    /**
     * @param  float  $maxWait  the longest it will wait for a reset before refusing to send
     */
    public function __construct(
        private RateLimitStore $store = new InMemoryRateLimitStore,
        private float $maxWait = 60.0,
        private ClockInterface $clock = new SystemClock,
        private Sleeper $sleeper = new SystemSleeper,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $key = $this->key($request);
        $until = $this->store->exhaustedUntil($key);

        if ($until !== null) {
            $wait = $until - $this->now();

            if ($wait > $this->maxWait) {
                throw new RateLimitExceededException($wait);
            }

            if ($wait > 0) {
                $this->sleeper->sleep($wait);
            }

            $this->store->clear($key);
        }

        $response = $next->handle($request);
        $this->remember($key, $response);

        return $response;
    }

    private function remember(string $key, ResponseInterface $response): void
    {
        // Retry-After takes precedence over the RateLimit fields (section 7).
        $wait = $response->getStatusCode() === 429 || $response->getStatusCode() === 503
            ? RetryAfter::from($response, $this->clock)
            : null;

        if ($wait === null) {
            $limit = RateLimits::mostRestrictive($response, $this->clock);
            $wait = $limit !== null && $limit->exhausted() ? $limit->resetIn : null;
        }

        if ($wait !== null && $wait > 0) {
            $this->store->exhaust($key, $this->now() + $wait);
        }
    }

    private function key(RequestInterface $request): string
    {
        $uri = $request->getUri();

        return $uri->getScheme().'://'.$uri->getAuthority();
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
