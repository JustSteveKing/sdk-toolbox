# SDK Toolbox

[![Latest version](https://img.shields.io/packagist/v/juststeveking/sdk-toolbox.svg)](https://packagist.org/packages/juststeveking/sdk-toolbox)
[![PHP version](https://img.shields.io/packagist/dependency-v/juststeveking/sdk-toolbox/php.svg)](https://packagist.org/packages/juststeveking/sdk-toolbox)
[![CI](https://github.com/JustSteveKing/sdk-toolbox/actions/workflows/ci.yml/badge.svg)](https://github.com/JustSteveKing/sdk-toolbox/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Total downloads](https://img.shields.io/packagist/dt/juststeveking/sdk-toolbox.svg)](https://packagist.org/packages/juststeveking/sdk-toolbox)
[![Licence](https://img.shields.io/packagist/l/juststeveking/sdk-toolbox.svg)](LICENSE.md)
[![Sponsor](https://img.shields.io/github/sponsors/JustSteveKing?label=sponsor&logo=githubsponsors)](https://github.com/sponsors/JustSteveKing)

A PHP library for building API SDKs. You describe the API with a connector and each endpoint with a small request class. The toolbox handles the rest the way the HTTP standards say a client should:

- retries;
- errors;
- pagination;
- authentication;
- testing.

It works with any PSR-18 HTTP client.

## Install

```bash
composer require juststeveking/sdk-toolbox
```

It needs PHP 8.3 or newer, and a PSR-18 client with PSR-17 factories. Most applications already have those; if yours doesn't, `composer require symfony/http-client nyholm/psr7`. Two extras are optional:

- `cuyz/valinor`, for mapping responses onto your own classes;
- `open-telemetry/api`, for tracing.

It's at 0.x, so expect the odd change before 1.0.

## Quick start

### 1. Describe the API

A connector says where the API is and how to authenticate:

```php
use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
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
```

Taking `$http` lets applications pass their own client, and lets your tests pass a fake.

### 2. Describe an endpoint

A request says what to send and what the response becomes. The `@implements` line is what makes `send()` return a `Project`, to PHPStan as well as at runtime:

```php
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;

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
        return Project::fromArray($response->json());
    }
}
```

`Project` is your own class. A request that sends data implements `HasBody` as well, and returns a `new JsonBody([...])` (or a form, multipart or patch body) from `body()`.

### 3. Send it

```php
$acme = new Acme(getenv('ACME_TOKEN'));

$project = $acme->send(new GetProject('prj_1'));
```

A 4xx or 5xx throws, so `result()` only ever sees a successful response.

### 4. Test it

```php
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

it('gets a project', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']));

    $project = (new Acme('test-token', $http))->send(new GetProject('prj_1'));

    expect($project->name)->toBe('Website');
    $http->assertSentTo('GET', '/v2/projects/prj_1');
});
```

[Getting started](docs/getting-started.md) goes further: resources that group requests, POST bodies and the result class, start to finish.

## What it does, and where to read more

### Requests and bodies

- **Request extras:** a request implements `HasQuery`, `HasHeaders` or `HasBody` for what it needs.
- **Bodies:** JSON, form, streamed multipart, JSON Merge Patch (with `MergePatch::diff($before, $after)`) and JSON Patch.
- **Paths** are appended to the base URI and can't escape it, so a caller's input can't send a request, or its credentials, to another host.

More in [Requests and bodies](docs/requests-and-bodies.md).

### Errors

A 404 throws `NotFoundException`, a 422 `UnprocessableContentException`, and so on, each carrying the response and any RFC 9457 problem the API sent. Map the API's own problem types to your SDK's exceptions:

```php
exceptions: new ProblemTypes(['https://api.acme.test/problems/out-of-credit' => OutOfCredit::class]),
```

More in [Errors](docs/errors.md).

### Authentication

| Need | Use |
|---|---|
| A static credential | `BearerToken`, `BasicAuth`, `HeaderKey` or `QueryKey` |
| OAuth 2.0, server to server | `ClientCredentials` |
| OAuth 2.0, for a user | `AuthorizationCode` with PKCE, then `RefreshingToken` |
| OAuth 2.0, for a CLI | the device flow |
| OAuth 2.0, registering at runtime | `ClientRegistration` (RFC 7591) |

Credentials don't go over plain HTTP unless you say so. Tokens refresh once, even across processes.

More in [Authentication](docs/authentication.md).

### Typed results and pagination

```php
return $response->map(Member::class, at: 'data');   // with cuyz/valinor installed

foreach ($acme->paginate(new ListMembers, new CursorPagination('meta.next_cursor')) as $member) {
    // every member, one page in memory at a time
}

$acme->paginate(new ListMembers, new CursorPagination('meta.next_cursor'))
    ->where('role', Role::Owner)
    ->first();                                       // fetches only the pages it needs

$response->collect('data')->pluck('name', 'id');     // untyped data, as a collection
```

Pagination follows `Link` headers, a next-page URL in the body, cursors, offsets, page numbers or Stripe's `starting_after`. A paginator is a lazy collection, with `map()`, `filter()`, `first()` and the other collection methods you'd expect, and nothing to install.

More in [Results and pagination](docs/results-and-pagination.md).

### Retries and rate limits

Retries are on by default and only repeat what's safe:

- a GET that hit a 503 is retried, for as long as `Retry-After` says;
- a POST that may have landed isn't.

Mark a POST `Idempotent` to send an Idempotency-Key and make it retryable. To wait for a rate limit to reset rather than collect 429s, give the connector a throttle:

```php
retry: RetryPolicy::standard()->withMaxAttempts(5),
throttle: new Throttle(maxWait: 30.0),
```

More in [Reliability](docs/reliability.md).

### Caching, ETags and long-running operations

- **Caching:** pass `cache: $psr16Cache` for an RFC 9111 HTTP cache.
- **Safe updates:** use `Precondition::ifMatch($etag)` so an update only applies if nobody changed the resource in between.
- **Long-running operations:** `$acme->await($request)` follows a 202 until the work is done.
- **Deprecation:** deprecated endpoints can be reported, or fail your test suite.

More in [HTTP semantics](docs/http-semantics.md).

### Logging and tracing

Pass `logger:` (PSR-3), `events:` (PSR-14) or `tracing:` (W3C Trace Context or OpenTelemetry). Every log line and event is redacted, so no credential reaches them.

More in [Observability](docs/observability.md) and [Middleware](docs/middleware.md).

### Testing

| Tool | Gives you |
|---|---|
| `FakeClient` | answers from a queue, with assertions on what was sent |
| `FakeTime` | retries without real sleeps |
| `Recorder` | real API responses, recorded once with secrets masked, replayed from then on |

More in [Testing](docs/testing.md).

### Webhooks and signatures

Verify incoming webhooks:

- `new StandardWebhooks('whsec_...')`;
- `new HmacWebhook($secret, header: 'X-Hub-Signature-256', prefix: 'sha256=')`, for GitHub-style signatures;
- `TimestampedHmacWebhook::stripe($secret)`, and `::slack()` and `::ghost()`, for signatures over a timestamp and the body.

Sign requests with RFC 9421 HTTP Message Signatures, and check bodies with Content-Digest.

More in [Integrity and webhooks](docs/integrity-and-webhooks.md).

## More

- [Security](docs/security.md): what the defaults protect against
- [Standards](docs/standards.md): every RFC, draft and PSR it follows, and how far
- [Porting an existing SDK](docs/porting.md): moving an SDK you already have onto the toolbox
- [Compared with Saloon](docs/compared-with-saloon.md): the differences, and when Saloon is the better choice

### With an AI agent

The package ships a skill that teaches an agent to build SDKs this way, in `resources/boost/skills/sdk-toolbox-development`.

- **Laravel Boost** picks it up on its own.
- **Anywhere else,** copy that directory into your agent's skills directory (`.claude/skills/` for Claude Code).

Working on the toolbox itself? Read [AGENTS.md](AGENTS.md).

### Contributing

Issues and pull requests are welcome: [CONTRIBUTING.md](CONTRIBUTING.md) says how, and questions are best asked in [Discussions](https://github.com/JustSteveKing/sdk-toolbox/discussions). Report security problems privately, as [SECURITY.md](SECURITY.md) describes. Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md), and [CHANGELOG.md](CHANGELOG.md) lists what changed in each release.

If the toolbox saves you time, you can [sponsor its development on GitHub](https://github.com/sponsors/JustSteveKing).

```bash
composer test      # Pest
composer analyse   # PHPStan, at max
composer lint      # Pint
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
