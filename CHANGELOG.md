# Changelog

Every notable change to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html): until 1.0, a minor release may change a public API, and the entry will say so.

## [Unreleased]

### Added

- `NextUrlPagination`, for APIs that put the next page's URL in the body. A relative URL resolves against the request, and one outside the base URI is refused like a `Link` header's.
- `idempotencyHeader` on the connector, and `withIdempotencyHeader()` on `RetryPolicy`, for APIs that take the key under another name, such as `X-Idempotency-Key`. The connector keeps its retry policy in step.

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
