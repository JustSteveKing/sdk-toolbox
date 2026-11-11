<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use Closure;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
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
 *
 * "Carrying an Idempotency-Key" means the header `idempotencyHeader`
 * names: the draft's `Idempotency-Key` unless the API uses another, such
 * as `X-Idempotency-Key`. A connector sets it on its policy to match the
 * header it sends. An API that takes the key in a body field instead
 * names it in `idempotencyField`, and a JSON or form body with that
 * top-level field counts as keyed.
 * - Nothing else is retried: not 400, 401, 404 or 422, which won't change
 *   on their own.
 *
 * Statuses are the default, but an API's own error codes can know better.
 * Resend answers 409 both for "a request with this key is still running",
 * worth retrying, and for "this key was used with a different body",
 * which fails the same way however often it's sent. withoutRetryingWhen()
 * lets an SDK veto a retry by reading the response, without replacing the
 * rules here.
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
     * @param  string  $idempotencyHeader  the header that marks a request as safe to repeat
     * @param  string|null  $idempotencyField  a JSON or form body field that marks it so, for an API that takes the key in the body
     * @param  (Closure(Response): bool)|null  $unless  a veto: true for a response that must not be retried, whatever its status
     */
    public function __construct(
        public int $maxAttempts = 3,
        public float $baseDelay = 0.5,
        public float $maxDelay = 8.0,
        public float $jitter = 1.0,
        public float $maxRetryAfter = 60.0,
        public ?float $deadline = null,
        private Randomizer $random = new Randomizer,
        public string $idempotencyHeader = 'Idempotency-Key',
        public ?string $idempotencyField = null,
        private ?Closure $unless = null,
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

    /**
     * For a request someone is waiting on: a web request, a CLI command a
     * person ran. The same rules about what's safe to retry, but it gives
     * up sooner: a `Retry-After` over 5 seconds isn't waited for, backoff
     * is capped at 2 seconds, and the whole sequence, waits included, ends
     * within 10. The default, standard(), suits queued work, where waiting
     * a minute for a rate limit to clear is better than failing the job.
     */
    public static function interactive(): self
    {
        return new self(maxDelay: 2.0, maxRetryAfter: 5.0, deadline: 10.0);
    }

    #[NoDiscard]
    public function withIdempotencyHeader(string $header): self
    {
        return $this->copy(idempotencyHeader: $header);
    }

    #[NoDiscard]
    public function withIdempotencyField(?string $field): self
    {
        return $this->copy(idempotencyField: $field, keepIdempotencyField: false);
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

    /**
     * A copy that never retries a response the closure says is final,
     * whatever its status. The closure gets the toolbox's Response, so it
     * can read the API's own error code from the body:
     *
     *     $policy->withoutRetryingWhen(
     *         fn (Response $response): bool => $response->get('name') === 'invalid_idempotent_request',
     *     );
     *
     * It only ever stops a retry; it can't make one. Called again, both
     * vetoes apply.
     *
     * @param  Closure(Response): bool  $veto
     */
    #[NoDiscard]
    public function withoutRetryingWhen(Closure $veto): self
    {
        $existing = $this->unless;

        return $this->copy(unless: $existing === null
            ? $veto
            : static fn (Response $response): bool => $existing($response) || $veto($response));
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

        if ($response !== null && $this->unless !== null && ($this->unless)(new Response($response, $request, $clock))) {
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
        $keyed = $request->hasHeader($this->idempotencyHeader) || ($this->idempotencyField !== null && self::bodyHas($request, $this->idempotencyField));
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
        ?string $idempotencyHeader = null,
        ?string $idempotencyField = null,
        bool $keepIdempotencyField = true,
        ?Closure $unless = null,
    ): self {
        return new self(
            maxAttempts: $maxAttempts ?? $this->maxAttempts,
            baseDelay: $baseDelay ?? $this->baseDelay,
            maxDelay: $maxDelay ?? $this->maxDelay,
            jitter: $this->jitter,
            maxRetryAfter: $this->maxRetryAfter,
            deadline: $keepDeadline ? $this->deadline : $deadline,
            random: $this->random,
            idempotencyHeader: $idempotencyHeader ?? $this->idempotencyHeader,
            idempotencyField: $keepIdempotencyField ? $this->idempotencyField : $idempotencyField,
            unless: $unless ?? $this->unless,
        );
    }

    /**
     * Whether a JSON or form body has a top-level field. Only a body that
     * can be rewound is read, and only one that could be retried anyway.
     */
    private static function bodyHas(RequestInterface $request, string $field): bool
    {
        $body = $request->getBody();

        if (! $body->isSeekable()) {
            return false;
        }

        $type = strtolower($request->getHeaderLine('Content-Type'));
        $content = (string) $body;

        if (str_contains($type, 'json')) {
            $data = json_decode($content, true);

            return is_array($data) && array_key_exists($field, $data);
        }

        if (str_starts_with($type, 'application/x-www-form-urlencoded')) {
            foreach (explode('&', $content) as $pair) {
                if (rawurldecode(explode('=', $pair, 2)[0]) === $field) {
                    return true;
                }
            }
        }

        return false;
    }

    private function backoff(int $attempt): float
    {
        $ceiling = min($this->maxDelay, $this->baseDelay * 2 ** ($attempt - 1));

        return $ceiling - $ceiling * $this->jitter * $this->random->nextFloat();
    }
}
