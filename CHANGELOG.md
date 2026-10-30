# Changelog

Every notable change to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html): until 1.0, a minor release may change a public API, and the entry will say so.

## [Unreleased]

A security fix: an API key sent as a query parameter could reach a log through a transport error's exception chain.

### Fixed

- **`TransportException::getPrevious()` no longer holds the HTTP client's exception unmasked.** Clients such as Guzzle quote the full URL in their messages, so with `QueryKey` the key was in that exception, and any log that writes out the chain, as Laravel's exception handler and Monolog do, recorded it. It's now a `RedactedClientException`, new in `Errors`: the client's class name, code, file and line, and its message with credentials masked by the connector's `Redactor`, and the same for the exceptions it wraps. The original isn't kept, so it can't leak through a dump either.

### Changed

- **Breaking:** `TransportException::getPrevious()` returns a `RedactedClientException` rather than the client's own exception, and the constructor takes one. Code that checked the previous exception's class reads `$previous->class` instead. Code that rethrew or inspected the client's exception for its request can use `$e->request`, the redacted snapshot.

## [0.0.7] - 2026-10-30

Collections, so the people using an SDK built on the toolbox can work with what it returns without writing the loop. Nothing to install.

### Added

- `Collections\Collection`: items in memory, with `first()`, `map()`, `filter()`, `where()`, `pluck()`, `groupBy()`, `sortBy()`, `sum()` and the rest, named as Laravel's collections name them. Immutable; items can be decoded JSON or typed objects, read by dotted path.
- `Collections\LazyCollection`: the same methods over items that arrive one at a time, reading only as many as a chain needs.
- `Response::collect($path)`: the JSON at a dotted path as a `Collection`.
- `Errors\CollectionException`, thrown for a write to a collection, an unknown `where()` operator, or a key that can't key an array.

### Changed

- **`Paginator` is a `LazyCollection`.** Every collection method works across pages, fetching only the pages it needs: `firstWhere('role', Role::Owner)` stops at the page holding the first owner.
- **Breaking: `Paginator::take()` returns a `LazyCollection`, not an array.** Call `->take(10)->all()` for the array. `all()` still returns an array, and `count($paginator)` now works, reading every page.

## [0.0.6] - 2026-10-30

Three additions for APIs that bend the usual shapes. Everything already in 0.0.5 behaves as it did: each one is opt-in.

### Added

- `poll()` on the connector, for asynchronous work that's a job you submit and then look up until a status field says it's finished, rather than a 202 and a monitor. `done` and an optional `failed` take the API's own words for each; it checks straight away, waits `Retry-After` or `interval` between checks, throws `OperationFailedException` or `OperationTimeoutException` as `await()` does, and returns the check's `result()`.
- `matchQuery` on `Recorder`: replay only when the query matches too, in any order, for APIs that answer every call at one path, such as `GET /search?engine=...`. Parameters the Redactor masks are left out, so a fake key still matches a recording made with a real one. Off by default, and fixtures are unchanged: they already keep each request's masked URI.
- `carry` on `NextUrlPagination`: query parameters to put back on each next URL that lacks them, for APIs whose next links drop options the first request sent, such as a format or a cache setting. A value the next URL sets is never overridden, and credentials come from the authenticator, never from carrying.

## [0.0.5] - 2026-10-29

Documentation only. No code changes.

### Documentation

- `await()` follows the monitor's `303` itself, so the connector needs a PSR-18 client that doesn't follow redirects on its own. Symfony's `Psr18Client` does by default, which hides the `303` from `await()` and drops credentials on a redirect to another host; `docs/http-semantics.md` shows how to turn that off, and notes that Guzzle's PSR-18 `sendRequest()` already leaves redirects alone.

## [0.0.4] - 2026-10-29

One fix, found by building an SDK against an API made with api-toolbox: `await()` and api-toolbox's status monitor now agree. Everything else behaves as it did.

### Fixed

- `await()` stopped at the first poll of a monitor that answers 200 while the operation runs, and handed the status document to `result()` as if it were the result. api-toolbox's monitor answers that way, so the two toolboxes didn't agree; Pingback, which uses both, found it. A 200 with `Retry-After` now means "still running, ask again then", and polling carries on.

### Added

- `OperationFailedException`, thrown by `await()` when the monitor reports `"status": "failed"` with an RFC 9457 problem in `error`, as api-toolbox's does. It carries the problem and the monitor URI. Before, the failure report went to `result()`.

## [0.0.3] - 2026-10-28

One addition, for the APIs that don't use RFC 9457, which is most of them. Nothing that was already there changes.

### Added

- `problems` on `StatusExceptions`: a function that reads an API's own error shape into a `ProblemDetails`, so every status exception carries the API's message and codes. The errors guide's example for such APIs used to drop the message for every status but 401.

## [0.0.2] - 2026-10-28

What building a real SDK on the toolbox, and researching others, showed it was missing. Everything already in 0.0.1 behaves as it did; the new options default to the old behaviour.

### Added

- `NextUrlPagination`, for APIs that put the next page's URL in the body. A relative URL resolves against the request, and one outside the base URI is refused like a `Link` header's.
- `idempotencyHeader` on the connector, and `withIdempotencyHeader()` on `RetryPolicy`, for APIs that take the key under another name, such as `X-Idempotency-Key`. The connector keeps its retry policy in step.
- `headerPrefix` on `StandardWebhooks`, for senders that use their own prefix, such as Svix's `svix-id`, `svix-timestamp` and `svix-signature`.
- `TimestampedHmacWebhook`, for an HMAC over a timestamp and the body, with `stripe()`, `slack()` and `ghost()` constructors. The timestamp is checked against a tolerance, every signature under the scheme's key is tried, and other keys are ignored.
- `rotating: true` on `RefreshingToken`, for servers that rotate refresh tokens and omit one when the old one is spent. The spent token is no longer kept and sent again later, which such a server reads as a replay.
- `scopeSeparator` on `OAuthClient`, for servers that list scopes with commas rather than RFC 6749's spaces. Every flow sends scopes that way and reads granted ones back the same way.
- Dynamic Client Registration (RFC 7591): `ClientRegistration`, `ClientMetadata` and `RegisteredClient`, whose `oauthClient()` authenticates the way the server registered it.

### Changed

- `TokenProvider` is public, with a new `ProvidedToken` authenticator, so an SDK can run a grant the toolbox doesn't have (a JWT bearer grant, token exchange) and keep the storage, single-flight refresh and retry on rejection.

## [0.0.1] - 2026-10-28

The first release.

### Added

- Connectors and typed requests: `send(Request<T>): T`, with request paths that can't leave the base URI.
- Bodies: JSON, form, streamed multipart, JSON Merge Patch with `diff()`, and JSON Patch.
- Errors: a class per status, and RFC 9457 problems mapped to your own exceptions, with credentials redacted everywhere.
- Authentication: bearer, Basic, header and query keys; OAuth 2.0 client credentials, authorisation code with PKCE, refresh and the device flow; refresh once across processes.
- Retries following RFC 9110's idempotency rule, with `Retry-After`, jitter and deadlines; Idempotency-Key (experimental); rate-limit headers (experimental) and a throttle.
- Typed results through Valinor, and pagination by `Link` header, cursor, offset, page or `starting_after`.
- An RFC 9111 cache, preconditions, Deprecation and Sunset, `Prefer` and 202 polling.
- PSR-3 logging, PSR-14 events, W3C Trace Context and OpenTelemetry spans.
- Content-Digest, RFC 9421 HTTP Message Signatures, and Standard Webhooks and HMAC webhook verification.
- Testing tools: a fake client, fake time and recorded fixtures.
- An agent skill for building SDKs with the toolbox.

[Unreleased]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.7...HEAD
[0.0.7]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.6...v0.0.7
[0.0.6]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.5...v0.0.6
[0.0.5]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.4...v0.0.5
[0.0.4]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/JustSteveKing/sdk-toolbox/releases/tag/v0.0.1
