# SDK Toolbox

Build PHP SDKs on PSR-18, typed end to end, with the HTTP standards an API client should honour.

It does the job Saloon does, with request objects, a connector, authentication and fakes, under different rules:

- **PSR-18 and PSR-17 only.** No HTTP client in `require`. Bring the one your application has, or let `php-http/discovery` find it.
- **Typed end to end.** A `Request<T>` comes back from `send()` as a `T`, to PHPStan as well as at runtime. The package runs at PHPStan max with no baseline.
- **No magic.** No trait booting, no static state, no mutable public configuration. Connectors are immutable.
- **Secure by default.**
  - Requests can't leave the base URI.
  - Credentials don't travel without TLS.
  - Exceptions, logs and recorded fixtures never hold a secret.
- **A published standard wherever one exists:**
  - RFC 9457 errors and RFC 9110 retries;
  - RFC 9111 caching and RFC 8288 pagination;
  - OAuth 2.0 with PKCE;
  - RFC 9421 signatures and Standard Webhooks;
  - [and the rest](docs/standards.md).

PHP 8.3 or newer, tested on 8.3, 8.4 and 8.5. It's at 0.x and not on Packagist yet.

## Install

```bash
composer require juststeveking/sdk-toolbox
```

You also need a PSR-18 client and PSR-17 factories, which most applications already have; `symfony/http-client` and `nyholm/psr7` will do if not. Optional: `cuyz/valinor` for typed results, `open-telemetry/api` for spans.

## An SDK, briefly

```php
use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;
use Psr\Http\Client\ClientInterface;

final class Acme extends Connector
{
    public function __construct(#[\SensitiveParameter] string $token, ?ClientInterface $http = null)
    {
        parent::__construct(
            baseUri: 'https://api.acme.test/v2',
            auth: new BearerToken($token),
            http: $http,
            userAgent: 'acme-php/1.0',
        );
    }
}

/** @implements Request<Project> */
final readonly class GetProject implements Request
{
    public function __construct(private string $id) {}

    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/projects/'.rawurlencode($this->id);
    }

    public function result(Response $response): Project
    {
        return $response->map(Project::class);
    }
}

$project = (new Acme($token))->send(new GetProject('prj_1'));   // a Project, to PHPStan too
```

That connector already:

- retries a GET that hit a 503, for as long as `Retry-After` says;
- never retries a POST that may have landed;
- throws a typed exception carrying the RFC 9457 problem;
- keeps the token out of every exception.

[Getting started](docs/getting-started.md) builds a complete SDK, resources and a test included.

## What's in it

| | |
|---|---|
| [Requests and bodies](docs/requests-and-bodies.md) | Typed requests; JSON, form, streamed multipart, Merge Patch with `diff()`, JSON Patch; a base URI nothing can escape |
| [Errors](docs/errors.md) | One exception interface; status classes; RFC 9457 problems mapped to your SDK's own exceptions |
| [Authentication](docs/authentication.md) | Bearer, Basic, API keys; OAuth client credentials, authorisation code with PKCE, device flow; refresh once across processes |
| [Results and pagination](docs/results-and-pagination.md) | Valinor-backed typed results; `Link`, cursor, Stripe-style, offset and page pagination in bounded memory |
| [Reliability](docs/reliability.md) | Retries that only repeat what's safe; `Retry-After`; deadlines; Idempotency-Key; rate-limit headers and a throttle |
| [HTTP semantics](docs/http-semantics.md) | An RFC 9111 cache; `If-Match` for lost updates; Deprecation and Sunset; `Prefer` and 202 polling |
| [Observability](docs/observability.md) | PSR-3 logs and PSR-14 events, redacted; W3C Trace Context; OpenTelemetry client spans |
| [Middleware](docs/middleware.md) | The pipeline's order and your own middleware; HTTPlug plugins still work underneath |
| [Testing](docs/testing.md) | A fake client with assertions, fake time, and fixtures recorded from the real API with secrets masked |
| [Integrity and webhooks](docs/integrity-and-webhooks.md) | Content-Digest; RFC 9421 signing and verifying; Standard Webhooks and HMAC webhooks |
| [Security](docs/security.md) | What the defaults protect against |
| [Porting an existing SDK](docs/porting.md) | What moving einvoicing/sdk onto the toolbox took |

## Compared with Saloon

Saloon is good, and much of this package's shape comes from it: request objects, a connector, fakes. The differences are the point:

| | Saloon 4 | SDK Toolbox |
|---|---|---|
| HTTP client | Guzzle, required | any PSR-18 client |
| Results | `dto(): mixed` | `send(Request<T>): T`, checked by PHPStan |
| Static analysis | PHPStan 7 with a baseline | PHPStan max, no baseline |
| Retries | every 4xx and 5xx, no method check | RFC 9110's idempotency rule, `Retry-After`, jitter, a deadline, Idempotency-Key |
| Rate limits | plugin, `Retry-After` only | IETF `RateLimit` and `X-RateLimit-*`, and a throttle |
| Errors | per-status classes | per-status classes plus RFC 9457, mapped by problem type |
| Caching | plugin, fixed TTL | RFC 9111: validators, 304s, `Vary`, keyed on credentials |
| Pagination | plugin, body only | `Link` headers and body strategies, bounded memory |
| Configuration | traits booted by name, mutable properties, static globals | constructor arguments, immutable copies |
| Async and pools | yes, on Guzzle promises | not yet |

Saloon has async requests, request pools and a Laravel plugin with `make:` commands, and this doesn't, yet. If you need concurrent requests today, use Saloon.

## For AI agents

The package ships an agent skill for building SDKs with it, in `resources/boost/skills/sdk-toolbox-development`.

- **Laravel Boost** finds it on its own.
- **Anywhere else,** copy that directory into your agent's skills directory, `.claude/skills/` for Claude Code.

[AGENTS.md](AGENTS.md) is for agents working on the toolbox itself.

## Development

```bash
composer test      # Pest: arch, RFC conformance, unit, memory and feature suites
composer analyse   # PHPStan max, with type assertions on a fixture SDK
composer lint      # Pint
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
