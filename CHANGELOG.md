# Changelog

Every notable change to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html): until 1.0, a minor release may change a public API, and the entry will say so.

## [Unreleased]

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

[Unreleased]: https://github.com/JustSteveKing/sdk-toolbox/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/JustSteveKing/sdk-toolbox/releases/tag/v0.0.1
