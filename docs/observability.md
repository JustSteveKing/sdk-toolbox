# Observability

```php
parent::__construct(
    baseUri: 'https://api.acme.test',
    logger: $psr3Logger,             // every event as a log line
    events: $psr14Dispatcher,        // every event as an object
    tracing: new OpenTelemetrySpans, // or new TraceContext(...)
);
```

## Events

| Event | Log level | When |
|---|---|---|
| `RequestSending` | debug | each attempt, as it goes to the HTTP client |
| `ResponseReceived` | debug, warning for 5xx | each attempt that got a response, with its duration |
| `RequestFailed` | warning | each attempt that got no response |
| `RetryScheduled` | info | the retry policy is trying again, and how long it'll wait |
| `RateLimitWaiting` | warning | the throttle is holding a request |
| `CredentialsRefreshed` | info | the API rejected the credentials; renewed, retrying once |
| `CacheHit` | debug | the HTTP cache answered, fresh or after a 304 |
| `DeprecationNoticed` | warning | an endpoint is deprecated, once per endpoint |

Every event implements `Events\Event`, with `level()`, `message()` and `context()`. The same objects go to the dispatcher and, as log lines, to the logger.

```
debug: Sending GET https://api.acme.test/v2/projects?api_key=%5Bredacted%5D
warning: GET https://api.acme.test/v2/projects?api_key=%5Bredacted%5D returned 503 in 212 ms
info: Retrying GET https://api.acme.test/v2/projects?api_key=%5Bredacted%5D in 0.41s after 503 (attempt 2)
debug: Sending GET https://api.acme.test/v2/projects?api_key=%5Bredacted%5D (attempt 2)
debug: GET https://api.acme.test/v2/projects?api_key=%5Bredacted%5D returned 200 in 98 ms
```

Events carry redacted request snapshots, never the PSR-7 request, so neither a listener nor a log line can leak a credential. The per-attempt instrumentation is only added when a logger or a dispatcher is given, and sits after the cache, so a cache hit logs `CacheHit` and no request lines.

## Tracing

Pick one of two, as `tracing:`.

### OpenTelemetry

`OpenTelemetrySpans` records a client span for every attempt, following OpenTelemetry's stable HTTP semantic conventions:

- **Name:** `{method}`. The raw path would make span names unbounded.
- **Attributes:**
  - `http.request.method`;
  - `server.address` and `server.port`;
  - `url.full`, redacted;
  - `http.response.status_code`;
  - `http.request.resend_count` on retries;
  - `error.type`, set to the status code or the exception class.
- **Status:** Error for 4xx, 5xx and network failures.
- **Propagation:** the span's own context goes out in `traceparent`, so the API's spans join the trace.

It needs `open-telemetry/api`, and uses OpenTelemetry's global tracer provider and propagator unless you pass your own. Without an SDK configured, OpenTelemetry's API does nothing, so it's safe to leave on.

### W3C Trace Context, without OpenTelemetry

`TraceContext` propagates `traceparent` with no dependencies. Give it the current trace, usually the one your application received:

```php
tracing: new TraceContext(fn () => [$request->header('traceparent'), $request->header('tracestate')]),
```

- **With a current trace,** every outgoing request joins it, with a new parent ID of its own as the W3C Recommendation requires (§3.2.2.3). `tracestate` is passed on unchanged.
- **Without one,** each request starts a trace.
- **A request that already carries a `traceparent`** is left alone.
- **An invalid `traceparent`** (version `ff`, all-zero IDs) is treated as no trace at all.
