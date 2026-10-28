# SDK Toolbox documentation

Start with [Getting started](getting-started.md). It builds a small SDK from nothing, test included, and every other page builds on what it sets up.

| Page | What it covers |
|---|---|
| [Getting started](getting-started.md) | A connector, typed requests, resources and a test |
| [Requests and bodies](requests-and-bodies.md) | Paths and the base URI, queries, headers, JSON, forms, multipart, Merge Patch, JSON Patch |
| [Errors](errors.md) | The exception hierarchy, RFC 9457 problems, mapping an API's own errors |
| [Authentication](authentication.md) | Bearer, Basic, API keys, and OAuth 2.0 with PKCE, device flow and refresh |
| [Results and pagination](results-and-pagination.md) | Typed results through a hydrator, and paginating by `Link`, cursor, offset or page |
| [Reliability](reliability.md) | Retries, `Retry-After`, deadlines, Idempotency-Key, rate limits and the throttle |
| [HTTP semantics](http-semantics.md) | Caching, conditional requests, Deprecation and Sunset, `Prefer` and 202 polling |
| [Observability](observability.md) | PSR-3 logging, PSR-14 events, W3C Trace Context and OpenTelemetry |
| [Middleware](middleware.md) | The pipeline's order, and writing your own |
| [Testing](testing.md) | The fake client, fake time, recorded fixtures and type assertions |
| [Integrity and webhooks](integrity-and-webhooks.md) | Content-Digest, HTTP Message Signatures, and verifying webhooks |
| [Security](security.md) | What the defaults protect against, and how |
| [Standards](standards.md) | Every RFC, draft and PSR the toolbox implements, and how far |
| [Porting an existing SDK](porting.md) | Moving an SDK you already have onto the toolbox |
| [Compared with Saloon](compared-with-saloon.md) | Where the two differ, and when Saloon is the better choice |

Everything here is for version 0.x. Until 1.0, a minor release can change a public API, and the changelog will say so.
