# Reliability

## Retries

Retries are on by default. They follow the one rule that makes retrying safe, RFC 9110 §9.2.2: don't repeat a request that may already have taken effect unless you know repeating it is harmless.

| What happened | Retried for |
|---|---|
| 408, 425, 429 or 503: the server says it didn't process the request | every method |
| 500, 502, 504, or no response at all | GET, HEAD, OPTIONS, PUT and DELETE, and any request with an Idempotency-Key |
| 409, the Idempotency-Key draft's "still in progress" | requests with an Idempotency-Key |
| 400, 401, 403, 404, 422 and the rest | never: they won't change on their own |

So a GET that hits a 502 is tried again, and a POST that hits a 502 isn't, because the first attempt may have created something.

### Where the request runs

The default, `RetryPolicy::standard()`, suits queued work: three attempts, and a `Retry-After` of up to a minute waited out, because waiting for a rate limit to clear is better than failing the job. Inside a web request, with a person watching a spinner, a minute is a long time, and failing fast is a reasonable choice. `RetryPolicy::interactive()` keeps the same rules about what's safe but gives up sooner: no `Retry-After` over 5 seconds, backoff capped at 2, and the whole sequence done within 10.

```php
new Acme($token, retry: RetryPolicy::interactive());
```

An SDK can't know where it's called from, so let the caller choose: take a `RetryStrategy` in the connector's constructor, and say in your README which preset suits which kind of code. The Resend case study found this was one of the ways an official SDK that never retries can feel better to use: it does nothing the caller didn't ask for.

### How long it waits

- **`Retry-After`** is honoured, as seconds or as an HTTP-date in any of the three forms RFC 9110 §5.6.7 allows. Some APIs send `retry-after-ms` for sub-second waits; it's read only when `Retry-After` is absent.
- **Without one,** the wait is random, up to a ceiling that doubles each time (0.5s, 1s, 2s...) and stops at 8 seconds. That's "full jitter", so many clients failing at once don't all retry at the same moment.
- **A `Retry-After` longer than `maxRetryAfter`** (60 seconds) gives up instead of sleeping. The exception's `$e->retryAfter` says how long the server wanted.
- **A deadline,** when set, caps the whole sequence, waits included.
- **A body that can't be rewound** is never sent twice.

```php
retry: RetryPolicy::standard(),                              // the default: 3 attempts in all
retry: RetryPolicy::standard()->withMaxAttempts(5)->withDeadline(30.0),
retry: new RetryPolicy(maxAttempts: 4, baseDelay: 1.0, maxDelay: 20.0, jitter: 0.5),
retry: RetryPolicy::none(),
```

Each retry goes back through the throttle, your middleware and authentication, so a refreshed token or a rate-limit wait applies to it.

RFC 9110 also says a client shouldn't automatically retry a failed automatic retry. That's why this is a small, deliberate attempt limit that an SDK author chooses, not a loop.

### Your own rules

The status rules are right for most APIs, but an API's own error codes can know better. Resend answers 409 both for "a request with this key is still running", which is worth retrying, and for "this key was used with a different body", which fails the same way however often it's sent. (The Idempotency-Key draft's §2.7 puts that second case at 422, which is never retried; Resend's choice is its own.) `withoutRetryingWhen()` vetoes a retry by reading the response, and leaves every other decision to the policy:

```php
retry: RetryPolicy::standard()->withoutRetryingWhen(
    fn (\JustSteveKing\SdkToolbox\Http\Response $response): bool => $response->get('name') === 'invalid_idempotent_request',
),
```

The closure gets the toolbox's `Response`, so `get()`, `json()` and `header()` all work, and reading the body doesn't use it up. A veto only stops a retry, it never makes one the status rules wouldn't, and it isn't asked when no response came back at all. Calling it twice applies both, and it survives the other `with*()` methods.

For anything a veto can't say, such as a different wait, an API with its own retry rules implements `RetryStrategy`: given the request, the response or error, the attempt number and the time elapsed, return seconds to wait, or null to stop.

```php
final readonly class AcmeRetries implements RetryStrategy
{
    public function __construct(private RetryPolicy $standard = new RetryPolicy) {}

    public function delay(RequestInterface $request, ?ResponseInterface $response, ?ClientExceptionInterface $error, int $attempt, float $elapsed, ClockInterface $clock): ?float
    {
        // Acme's 429 for an exhausted plan won't clear by waiting.
        if ($response?->getStatusCode() === 429 && str_contains((string) $response->getBody(), 'plan limit')) {
            return null;
        }

        return $this->standard->delay($request, $response, $error, $attempt, $elapsed, $clock);
    }
}
```

## Idempotency-Key

A POST becomes safe to retry when the server can recognise a repeat. Implement `Idempotent` and the connector sends an Idempotency-Key:

```php
final readonly class CreateCharge implements HasBody, Idempotent, Request
{
    public function __construct(private int $amount, private ?string $orderId = null) {}

    public function idempotencyKey(): ?string
    {
        return $this->orderId;   // or null for a random UUID
    }

    // method(), path(), body(), result()...
}
```

- **One key per `send()`,** kept for every retry of that send.
- **The key** is a random UUID unless the request returns its own. Tying it to something of yours, such as an order ID, means a retry after a crash reuses it too.
- **Never reuse a key with a different payload** (the draft's §2.2).
- **A replay** is the stored answer to an earlier request with the same key, sent back rather than acted on again. `$response->replayed()` is true when the API says so with `Idempotent-Replayed: true`, the convention Resend, Deliveroo and ePay follow (the draft doesn't define one). Pass the header name for an API with its own, such as `replayed('X-Idempotency-Replayed')`. It's how a caller tells "sent now" from "already sent", say before charging a card or emailing a receipt.
- **The default format** is a bare token, `Idempotency-Key: 8e03978e-...`, which is what Stripe, Resend and most APIs in use take. The draft (§2.1) defines the value as a Structured Field String, `Idempotency-Key: "8e03978e-..."`; for an API that follows it to the letter, pass `idempotencyKeys: IdempotencyKey::Quoted` to the connector. Until 0.1.0 the quoted form was the default, and sent to an API that wants a bare key it put the quotes inside the key without any error to say so.
- **The header** is the draft's `Idempotency-Key` unless the API names another: Buttondown, for one, wants `X-Idempotency-Key`. Pass `idempotencyHeader: 'X-Idempotency-Key'` to the connector. It tells its `RetryPolicy` too, including one set later with `withRetry()`, so keyed requests are still retried; a custom `RetryStrategy` reads the request itself.
- **A body field instead of a header,** for an API that takes its deduplication key in the body: pass `idempotencyField: 'dedupe_id'` to the connector. The key, generated or the request's own, goes in that top-level field of the request's `JsonBody` or `FormBody`, replacing any value the body set there, and no header is sent. It's the raw key: `idempotencyKeys` only formats the header. The body is built once per `send()`, so every retry carries the same key, and the connector's `RetryPolicy` is told the field (`withIdempotencyField()`), so a JSON or form body with it counts as keyed and is retried as a keyed request would be. Any other body (raw, multipart, a JSON list) or none at all throws `InvalidRequestException` before anything is sent, since there's no field to put the key in.

Experimental: this is draft-ietf-httpapi-idempotency-key-header-08, and its section on retries is still open.

## Rate limits

`$response->rateLimit()` returns the most restrictive policy the response reported, as a `RateLimit`:

- `remaining` and `limit`;
- `resetIn`, in seconds;
- `window`;
- `exhausted()`.

`rateLimits()` returns all of them.

It reads:

- **the IETF fields,** `RateLimit` and `RateLimit-Policy` (draft-11), as Structured Fields, joined by policy name. Experimental: the draft expires on 2026-11-24.
- **failing those, the older conventions:** `X-RateLimit-Limit`, `-Remaining` and `-Reset`, or the same without the `X-`, including GitHub's `x-ratelimit-resource`.

A reset can be seconds from now in one API and a Unix timestamp in another, and nothing in the header says which. A value large enough to be a timestamp is read as one (milliseconds too). A malformed field is ignored, not half-read, as the draft requires.

### The throttle

Rather than sending requests the API will turn away with 429, give the connector a `Throttle`:

```php
throttle: new Throttle(maxWait: 30.0),
throttle: new Throttle(new Psr16RateLimitStore($cache)),   // shared between processes
```

1. When a response says the quota is spent (the most restrictive policy at zero, or a 429 or 503 with `Retry-After`), the throttle remembers when it resets.
2. The next request to that host waits until then.
3. If the reset is further off than `maxWait`, it throws `RateLimitExceededException` without sending, with `$e->retryAfter` set.

It acts only on what the server reported, never assuming quota is left. A 429's `Retry-After` wins over the RateLimit fields, and a response with `Age` came from a cache, so its rate-limit fields are ignored (draft §7).

## Time, in tests

Every wait goes through a PSR-20 clock and a `Sleeper`. Pass `Testing\FakeTime` as both and nothing waits: the clock moves on instead, and every sleep is recorded. See [Testing](testing.md#time).
