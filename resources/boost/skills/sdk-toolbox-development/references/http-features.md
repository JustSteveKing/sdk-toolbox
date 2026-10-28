# HTTP features

Each is opt-in on the connector or the request.

## Caching

Pass `cache: $psr16Cache` to the connector for an RFC 9111 private cache.

- It handles `max-age`, ETag revalidation, 304s and `Vary`, and is keyed on credentials.
- Don't add your own response caching around `send()`.

## Lost updates: ETag and If-Match

1. Read the tag: `$response->etag()`.
2. The update request implements `Requests\Conditional` and returns `Http\Precondition::ifMatch($etag)`.
3. If somebody changed the resource in between, the API's 412 becomes `Errors\PreconditionFailedException`.

`Precondition::ifNoneMatchAny()` creates without overwriting. Weak tags are refused in `If-Match`.

## Deprecation and Sunset

- **Reading:** `$response->deprecation()` returns a `Deprecation\DeprecationNotice`.
- **Being told:** `deprecations: new Deprecation\Deprecations($handler)` on the connector calls the handler once per endpoint.
- **In an SDK's own tests,** use `Deprecations::strict()`, so a deprecated endpoint fails the build.

## Prefer and long-running operations

- **Preferences:** a request implements `Requests\HasPreferences` and returns `Http\Preferences::none()->respondAsync()`, `->returnMinimal()` or `->wait(10)`.
- **202 operations:** `$sdk->await($request, timeout: 300.0)` follows 202 plus `Location` to a 303 or 200, then returns `result()`.

## Logging, events, tracing

On the connector:

- `logger:` takes a PSR-3 logger;
- `events:` takes a PSR-14 dispatcher;
- `tracing:` takes `new Observability\OpenTelemetrySpans` (needs `open-telemetry/api`) or `new Observability\TraceContext(fn () => [$traceparent, $tracestate])`.

Everything they see is already redacted. Don't log requests yourself in middleware: you'd see them before authentication anyway, without credentials.

## Integrity and signatures

- **Content-Digest:** `->withMiddleware(new Integrity\Digests)` adds it to request bodies and checks responses.
- **Signing requests:** `new Signatures\SignRequests(Signatures\SigningKey::ed25519($id, $pem), ['@method', '@authority', '@path'])`. Add it after `Digests` so the body is covered.
- **Verifying:** `new Signatures\MessageVerifier(keys: fn (string $id) => ..., required: [...], maxAge: 300)`.

## Webhooks

The SDK's users verify incoming webhooks with one of these, from `Webhooks\`:

- `StandardWebhooks('whsec_...')`, whose `check()` returns the `webhook-id` to deduplicate on (`headerPrefix: 'svix-'` for Svix's headers);
- `HmacWebhook($secret, header: 'X-Signature', prefix: 'sha256=')`;
- `SignedWebhook($messageVerifier)`.

Expose one from the SDK, configured for the API, rather than leaving users to pick. Give it the PSR-7 request before anything parses the body.
