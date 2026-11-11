# Changelog

Every notable change to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html): until 1.0, a minor release may change a public API, and the entry will say so.

## [Unreleased]

Lessons from rebuilding part of Resend's API on the toolbox, beside Resend's official SDK, and comparing the two. The reliability layer held up; almost everything here is about how it feels to use.

### Added

- `NoHttpClientException`, in `Errors`, when a connector is built without a client or PSR-17 factories and `php-http/discovery` finds none. `composer require` on an SDK succeeds with nothing installed, because discovery declares that it provides the implementations, so the first sign used to be discovery's own `NotFoundException` from inside the constructor. The new message names the client or factory that's missing and the `composer require` that fixes it; discovery's exception is kept as the previous one. It's a `LogicException`, since no retry fixes a missing package. The `psr/http-client-implementation` and `psr/http-factory-implementation` requirements stay: they're what lets discovery's plugin install a client when it's allowed to.
- Untyped requests, in `Requests\Untyped`: `JsonRequest::get()`, `post()`, `put()`, `patch()` and `delete()` take arrays and return the whole decoded body (`[]` for an empty one), through the same retries, throttle, cache and exception mapper as a typed request. An SDK can offer them beside its typed resources, so a field or an endpoint it hasn't caught up with is still reachable. `post()` carries an idempotency key only when given one, as `IdempotentJsonBodyRequest`, and never generates one.
- `RetryPolicy::withoutRetryingWhen(Closure $veto)`: a copy that never retries a response the closure says is final, whatever its status, for APIs whose own error codes know better than the status. Resend uses 409 both for a keyed request still running (retried, as the Idempotency-Key draft suggests) and for a key reused with a different body, which the policy used to retry to the attempt limit. The closure gets the toolbox's `Response`, so it can read the body; it only ever stops a retry, isn't asked when there's no response, and every veto applies when it's called more than once.
- `Response::replayed()`: true when the API marks the response as the stored answer to an earlier request with the same idempotency key, by `Idempotent-Replayed: true` (Resend, Deliveroo, ePay) or a header named as its argument. The Idempotency-Key draft defines no such header, so it's listed among the conventions in the standards page.
- `RetryPolicy::interactive()`, for a request someone is waiting on: the same rules about what's safe to retry, but no `Retry-After` over 5 seconds is waited for, backoff is capped at 2 seconds, and the whole sequence ends within 10. `standard()`, the default, is unchanged and suits queued work, where it waits out a `Retry-After` of up to a minute.

## [0.0.9] - 2026-11-08

Feedback from SDKs built on the toolbox against real APIs. Everything already in 0.0.8 behaves as it did: each addition is opt-in.

### Added

- `sendUnauthenticated($uri, $request)` on the connector, for APIs that hand back a pre-signed URL on another host for an upload. It sends the request to that absolute `https` URI as given, with no authenticator, no connector headers and none of the connector's middleware, only the request's own headers and the User-Agent, and returns the `Response` as `response()` does. Plain `http` is allowed only on an `insecure` connector, and a relative URI or one with user info is refused. Retries, events, logging and tracing apply as they do for `send()`, with the URI redacted. Every other request is held to the base URI as before.
- `ListFormat`, in `Http`, for APIs that read a list in a query or form as `expand[]=a&expand[]=b` (`Brackets`) or `expand=a&expand=b` (`Repeated`) rather than PHP's `expand[0]=a` (`Indexed`, the default). Set it with `queryLists` on the connector and as the second argument to `FormBody`, and `QueryString::build()` takes one. Only lists change: keyed arrays are `key[name]=value` as before, and booleans, enums and nulls encode as they did.
- `hasMoreAt: null`, `limit` and `limitParameter` on `StartingAfterPagination`, for APIs that page after the last ID but send no "more" flag. The first request sends the limit, and paging stops on a page shorter than it or an empty one, so it never asks past the end. A limit below one is refused, as `OffsetPagination` refuses one. With a flag, nothing changes.
- `idempotencyField` on the connector, for APIs that take their deduplication key in the body rather than a header. An `Idempotent` request's key, generated or its own, goes in that top-level field of a JSON or form body, raw, and the header isn't sent. Every retry of one `send()` carries the same key, and the connector tells its `RetryPolicy` the field (`withIdempotencyField()`, new), so a keyed body is retried as a keyed header is. Any other body, or none, throws `InvalidRequestException` before anything is sent.
- `withField()` on `JsonBody` and `FormBody`: a copy with one more top-level field.
- Token revocation (RFC 7009): `OAuthClient::revoke($token, $hint)` sends a token to the revocation endpoint with its `token_type_hint`, authenticated as the client is at the token endpoint. `revoke()` on `RefreshingToken`, `ClientCredentials` and `TokenProvider` revokes the stored refresh and access tokens and forgets them, inside the refresh lock. The endpoint is `revocationEndpoint`, new on `OAuthClient` and `RegisteredClient::oauthClient()`; a client without one refuses to revoke.
- `OAuthClient::fromMetadata()`: a client built from an RFC 8414 authorization server metadata document you've fetched, with its token, authorisation, device authorisation and revocation endpoints.

### Changed

- The `Redactor` masks the signature parameters common pre-signed URLs carry by default: `X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `X-Goog-Signature` and `X-Goog-Credential`, beside the `signature` and `sig` it already masked. This only hides more in exceptions, logs, events and fixtures, so nothing that worked before breaks; a `Recorder` with `matchQuery` leaves them out of the comparison, as it does every masked parameter.

## [0.0.8] - 2026-10-30

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

[Unreleased]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.9...HEAD
[0.0.9]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.8...v0.0.9
[0.0.8]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.7...v0.0.8
[0.0.7]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.6...v0.0.7
[0.0.6]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.5...v0.0.6
[0.0.5]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.4...v0.0.5
[0.0.4]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/JustSteveKing/sdk-toolbox/releases/tag/v0.0.1
