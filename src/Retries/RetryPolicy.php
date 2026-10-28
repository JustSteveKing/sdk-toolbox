<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Method;
use NoDiscard;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Randomizer;

/**
 * When to try a request again, built on what RFC 9110 says is safe.
 *
 * A client SHOULD NOT automatically retry a non-idempotent request unless
 * it knows the request wasn't applied (RFC 9110 section 9.2.2). So:
 *
 * - **Any method** is retried on 408, 425, 429 and 503. The server is
 *   saying it didn't process the request: it timed out reading it, wouldn't
 *   risk replay (RFC 8470), turned it away for rate, or was unavailable.
 * - **Idempotent methods**, and any request carrying an Idempotency-Key,
 *   are also retried on 500, 502 and 504, and when no response came back
 *   at all, because the first attempt may or may not have landed.
 * - **A keyed request** is retried on 409, which the Idempotency-Key draft
 *   uses for "the original is still in progress".
 * - Nothing else is retried: not 400, 401, 404 or 422, which won't change
 *   on their own.
 *
 * Waits honour `Retry-After` when the server sends one, otherwise back off
 * exponentially with jitter, capped. A `Retry-After` longer than
 * `maxRetryAfter` gives up rather than sleeping for it, and a deadline caps
 * the whole sequence, waits included.
 *
 * RFC 9110 also says a client shouldn't retry a failed automatic retry.
 * That's why this is something an SDK configures deliberately, with a
 * small attempt limit, rather than a loop.
 */
final readonly class RetryPolicy implements RetryStrategy
{
    public const array ANY_METHOD = [408, 425, 429, 503];

    public const array IDEMPOTENT_ONLY = [500, 502, 504];

    /**
     * @param  int  $maxAttempts  the first attempt included
     * @param  float  $baseDelay  seconds before the first retry, before jitter
     * @param  float  $maxDelay  the cap on any backoff wait
     * @param  float  $jitter  how much of each wait is random: 1.0 is full jitter, 0.0 none
     * @param  float  $maxRetryAfter  the longest Retry-After worth waiting for
     * @param  float|null  $deadline  seconds the whole sequence may take, waits included
     */
    public function __construct(
        public int $maxAttempts = 3,
        public float $baseDelay = 0.5,
        public float $maxDelay = 8.0,
        public float $jitter = 1.0,
        public float $maxRetryAfter = 60.0,
        public ?float $deadline = null,
        private Randomizer $random = new Randomizer,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidRequestException('A retry policy needs at least one attempt.');
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new InvalidRequestException('Jitter is a fraction, from 0.0 to 1.0.');
        }
    }

    public static function standard(): self
    {
        return new self;
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    #[NoDiscard]
    public function withMaxAttempts(int $maxAttempts): self
    {
        return $this->copy(maxAttempts: $maxAttempts);
    }

    #[NoDiscard]
    public function withDeadline(?float $seconds): self
    {
        return $this->copy(deadline: $seconds, keepDeadline: false);
    }

    #[NoDiscard]
    public function withBackoff(float $baseDelay, float $maxDelay): self
    {
        return $this->copy(baseDelay: $baseDelay, maxDelay: $maxDelay);
    }

    public function delay(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?ClientExceptionInterface $error,
        int $attempt,
        float $elapsed,
        ClockInterface $clock,
    ): ?float {
        if ($attempt >= $this->maxAttempts || ! $this->retryable($request, $response, $error)) {
            return null;
        }

        $retryAfter = $response === null ? null : RetryAfter::from($response, $clock);

        if ($retryAfter !== null && $retryAfter > $this->maxRetryAfter) {
            return null;
        }

        $delay = $retryAfter ?? $this->backoff($attempt);

        if ($this->deadline !== null && $elapsed + $delay > $this->deadline) {
            return null;
        }

        return $delay;
    }

    private function retryable(RequestInterface $request, ?ResponseInterface $response, ?ClientExceptionInterface $error): bool
    {
        $keyed = $request->hasHeader('Idempotency-Key');
        $repeatable = $keyed || (Method::tryFrom(strtoupper($request->getMethod()))?->isIdempotent() ?? false);

        if ($response === null) {
            // Only a network failure might be transient; a client that
            // rejected the request itself will reject it again.
            return $error instanceof NetworkExceptionInterface && $repeatable;
        }

        $status = $response->getStatusCode();

        return in_array($status, self::ANY_METHOD, true)
            || ($repeatable && in_array($status, self::IDEMPOTENT_ONLY, true))
            || ($keyed && $status === 409);
    }

    private function copy(
        ?int $maxAttempts = null,
        ?float $baseDelay = null,
        ?float $maxDelay = null,
        ?float $deadline = null,
        bool $keepDeadline = true,
    ): self {
        return new self(
            maxAttempts: $maxAttempts ?? $this->maxAttempts,
            baseDelay: $baseDelay ?? $this->baseDelay,
            maxDelay: $maxDelay ?? $this->maxDelay,
            jitter: $this->jitter,
            maxRetryAfter: $this->maxRetryAfter,
            deadline: $keepDeadline ? $this->deadline : $deadline,
            random: $this->random,
        );
    }

    private function backoff(int $attempt): float
    {
        $ceiling = min($this->maxDelay, $this->baseDelay * 2 ** ($attempt - 1));

        return $ceiling - $ceiling * $this->jitter * $this->random->nextFloat();
    }
}
