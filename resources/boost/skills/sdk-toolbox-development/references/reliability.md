# Reliability

## Retries

On by default (`Retries\RetryPolicy::standard()`: 3 attempts, jittered backoff from 0.5s capped at 8s, `Retry-After` honoured up to 60s).

| Outcome | Retried for |
|---|---|
| 408, 425, 429, 503 | any method |
| 500, 502, 504, network failure | GET, HEAD, OPTIONS, PUT, DELETE, and requests with an Idempotency-Key |
| 409 | requests with an Idempotency-Key |
| everything else | never |

Configure on the connector:

```php
retry: RetryPolicy::standard()->withMaxAttempts(5)->withDeadline(30.0),
retry: RetryPolicy::interactive(),
retry: RetryPolicy::none(),
```

`standard()` suits queued work (`Retry-After` waited out up to 60s); `interactive()` suits code a person is waiting on (up to 5s, everything done within 10s). Always let the SDK's caller pass their own `RetryStrategy`, and say in the README which preset fits where.

For an API error code that must never be retried, whatever its status (Resend's `invalid_idempotent_request` 409, a 429 for an exhausted plan), use `RetryPolicy::standard()->withoutRetryingWhen(fn (Http\Response $response): bool => ...)`. It only vetoes, reading the body is safe, and the status rules still decide everything else. For a different wait or other rules, implement `Retries\RetryStrategy` and delegate the rest to a `RetryPolicy`.

Never write a retry loop around `send()`.

## Idempotency-Key

When the API supports Idempotency-Key, implement `Requests\Idempotent` on its POST requests. Return a stable key from the caller (an order ID) or null for a random UUID. The same key is sent on every retry of one `send()`.

- **Format:** pass `idempotencyKeys: Retries\IdempotencyKey::Bare` to the connector for APIs, Stripe among them, that want an unquoted key.
- **Header:** pass `idempotencyHeader: 'X-Idempotency-Key'` for an API that names it differently; the connector keeps its `RetryPolicy` in step.
- **Body field:** pass `idempotencyField: 'dedupe_id'` for an API that takes the key in the body. The raw key goes in that field of a `JsonBody` or `FormBody`, no header is sent, and retries keep the same key; any other body, or none, throws `Errors\InvalidRequestException`.
- **Replays:** `$response->replayed()` reads `Idempotent-Replayed: true` (pass another header name if the API uses one); surface it where a caller needs to know "already done", such as from a send or a charge.
- **Status:** experimental, since the draft is still changing.

## Rate limits

- **Reading:** `$response->rateLimit()` reads IETF `RateLimit` (experimental) or `X-RateLimit-*` into a `RateLimits\RateLimit` (`remaining`, `limit`, `resetIn`, `exhausted()`).
- **Not hitting 429s:** pass `throttle: new RateLimits\Throttle(maxWait: 30.0)` to the connector, with `new RateLimits\Psr16RateLimitStore($cache)` when several processes share a quota.
- **When the wait is too long,** the throttle throws `Errors\RateLimitExceededException` without sending.
