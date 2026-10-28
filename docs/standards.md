# Standards

What the toolbox implements, and how far. "Experimental" marks a draft that is still changing: its feature may change in a minor release when the draft does.

## HTTP and IETF

| Standard | Status | In the toolbox |
|---|---|---|
| RFC 9110 HTTP Semantics | Internet Standard | method safety and idempotency (§9.2) for retries; `Retry-After` (§10.2.3) in all three date forms; conditional requests (§13) |
| RFC 9111 HTTP Caching | Internet Standard | a private cache: freshness, validation, 304s, `Vary`, invalidation; no heuristic freshness |
| RFC 3986 URI | Internet Standard | parsing, resolution and dot-segment removal, tested against every §5.4 example |
| RFC 6585 Additional Status Codes | Proposed Standard | 428 and 429; 429 never cached |
| RFC 9457 Problem Details | Proposed Standard | parsed as a consumer should (§3); mapped to exceptions by `type` |
| RFC 8288 Web Linking | Proposed Standard | `Link` parsing per Appendix B, for pagination and deprecation links |
| RFC 9745 Deprecation header | Proposed Standard | read, reported once per endpoint, strict mode for tests |
| RFC 8594 Sunset header | Informational | read alongside Deprecation |
| RFC 7240 Prefer | Proposed Standard | `Prefer` and `Preference-Applied`; the 202 polling pattern is convention, not RFC |
| RFC 7396 JSON Merge Patch | Proposed Standard | a body, and `diff()` that refuses what Merge Patch can't express |
| RFC 6902 JSON Patch, RFC 6901 JSON Pointer | Proposed Standard | a body builder with pointer validation |
| RFC 7578 multipart/form-data | Proposed Standard | a streamed body |
| RFC 9651 Structured Fields | Proposed Standard | lists, dictionaries, every bare item type, inner lists and parameters |
| RFC 9530 Digest Fields | Proposed Standard | `Content-Digest`, sha-256 and sha-512 |
| RFC 9421 HTTP Message Signatures | Proposed Standard | signing and verifying with hmac-sha256 and ed25519; no component parameters, RSA or ECDSA yet |
| RFC 7617 Basic, RFC 6750 Bearer | Proposed Standard | authenticators; `WWW-Authenticate` challenges parsed (RFC 9110 §11.6.1) |
| RFC 6749 OAuth 2.0 | Proposed Standard | client credentials, authorisation code, refresh |
| RFC 7636 PKCE | Proposed Standard | S256 only, for every client |
| RFC 8628 Device Authorization | Proposed Standard | start and poll |
| RFC 9700 OAuth Security BCP | Best Current Practice | PKCE everywhere, no implicit grant, single-flight refresh |
| draft-ietf-httpapi-ratelimit-headers-11 | Internet-Draft, expires 2026-11-24 | **experimental:** `RateLimit` and `RateLimit-Policy` read |
| draft-ietf-httpapi-idempotency-key-header-08 | Internet-Draft | **experimental:** keys sent and kept across retries |
| draft-ietf-httpapi-privacy | Internet-Draft, in the RFC Editor queue | no credentials over plain HTTP without opting in |

## W3C and others

| Standard | Status | In the toolbox |
|---|---|---|
| W3C Trace Context Level 1 | Recommendation | `traceparent` and `tracestate` propagation |
| OpenTelemetry HTTP semantic conventions | Stable | a client span per attempt |
| Standard Webhooks 1.0.0 | Community specification | `v1` and `v1a` verification, signing for tests |

## PHP-FIG

| PSR | Used for |
|---|---|
| PSR-7, PSR-17 | every request and response |
| PSR-18 | the transport: any client, discovered or passed |
| PSR-3 | logging |
| PSR-14 | events |
| PSR-16 | the HTTP cache, token store and rate-limit store |
| PSR-20 | every clock |

## Not standards, but conventions the toolbox reads

- `X-RateLimit-*` and `RateLimit-Limit/Remaining/Reset`, including GitHub's;
- `retry-after-ms`;
- cursor, offset, page and Stripe-style `starting_after` pagination in response bodies, and a next-page URL in the body, treated like a `Link` header;
- bare (unquoted) Idempotency-Key values.
