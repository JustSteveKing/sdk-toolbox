# Compared with Saloon

Saloon is good, and much of this package's shape comes from it: request objects, a connector, fakes in tests. If you've used it, the toolbox will feel familiar. The differences are in what happens underneath.

| | Saloon 4 | SDK Toolbox |
|---|---|---|
| HTTP client | Guzzle, required | any PSR-18 client |
| What `send()` returns | `mixed` | the request's own type, checked by PHPStan |
| Static analysis | PHPStan 7 with a baseline | PHPStan max, no baseline |
| Retries | every 4xx and 5xx, no method check | only what RFC 9110 says is safe to repeat, honouring `Retry-After`, with jitter, a deadline and Idempotency-Key |
| Rate limits | a plugin, reading `Retry-After` | IETF `RateLimit` and `X-RateLimit-*` read on every response, and an optional throttle |
| Errors | a class per status | a class per status, plus RFC 9457 problems mapped to your own exceptions |
| Caching | a plugin, with a fixed lifetime | RFC 9111: ETags, 304s, `Vary`, keyed on credentials |
| Pagination | a plugin, reading the body | `Link` headers and body strategies, in bounded memory |
| Configuration | traits booted by name, mutable properties, static globals | constructor arguments and immutable copies |
| Async and pools | yes, on Guzzle promises | not yet |

Saloon has async requests, request pools and a Laravel plugin with `make:` commands, and the toolbox doesn't, yet. If you need concurrent requests today, use Saloon.

Saloon's v4 release fixed three security holes that came from convenience features: unserializing stored tokens, absolute URLs overriding the base URL, and fixture names that could reach outside their directory. The toolbox was designed with those in mind; [Security](security.md) covers how.
