# SDK Toolbox

A PHP library for building API SDKs that behave well when the network doesn't.

## Why this exists

I've written a lot of SDKs: for Sevalla, Ollama, Lemon Squeezy, OpenLibrary, Envoyer and einvoicing.dev, among others. Every one of them needed the same handful of things, built again each time: retries, error handling, pagination, authentication, and some way to test it all without hitting the real API.

Those are easy to get subtly wrong, and the mistakes don't show until production:

- a retry loop that repeats a POST and charges a card twice;
- an exception message with the API key sitting in its URL, on its way to an error tracker;
- pagination that quietly holds every page in memory.

My earlier shared foundations, `juststeveking/php-sdk` and `juststeveking/sdk-tools`, covered the plumbing. This one goes further, and the idea behind it is simple: most of the hard questions an API client faces were answered years ago, in RFCs. RFC 9110 says which requests are safe to retry, and the response's own `Retry-After` says how long to wait first. RFC 9457 gives an error a type you can match on, instead of a message you have to parse. A client that follows those answers behaves well without its author having to think about any of it, so the toolbox follows them, and your SDK gets that for free.

It's the client-side twin of [api-toolbox](https://github.com/JustSteveKing/api-toolbox), which makes a Laravel API send all of those headers properly. This makes a PHP client read them.

## What it's like to use

You describe the API once, with a connector:

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

And each endpoint as a small request class, which says what to send and what comes back:

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
        return $response->map(Project::class);
    }
}
```

Then you send it, and get a `Project` back, which PHPStan knows about too:

```php
$project = (new Acme($token))->send(new GetProject('prj_1'));
```

That's the whole pattern. If you've used Saloon, it'll feel familiar, and that's deliberate.

## What you don't have to write

The point of the toolbox is the code you no longer write, or get subtly wrong, in every SDK.

**Retries.** That GET gets retried if Acme answers 503, waiting as long as Acme asks. A POST that hits a 502 doesn't, because the first attempt may already have created something. When you do want to retry a POST, mark it idempotent, and it goes out with an Idempotency-Key that stays the same on every attempt.

**Errors.** A 404 becomes a `NotFoundException`, not a generic HTTP error you have to inspect. If the API speaks RFC 9457, the problem it sent comes along with the exception, and you can map its problem types to your own SDK's exceptions in one line.

**Secrets.** Your users' API keys stay out of exception messages, log lines, traces and recorded test fixtures. Requests can't wander off to another host, even when the API's own next-page link tells them to.

**Lists.** Paginating a million records holds one page at a time, whether the API pages by `Link` header, cursor, offset or Stripe's `starting_after`.

**OAuth.** Client credentials, authorisation code with PKCE, and the device flow for CLIs. Tokens refresh once, even when ten workers notice they've expired at the same moment.

**Testing.** A fake HTTP client with proper assertions, a fake clock so retries don't make your tests slow, and a recorder that captures real API responses once, masks the secrets, and replays them from then on.

There's more, from HTTP caching to webhook verification, and the [docs](docs/README.md) cover all of it.

## What it won't do

It doesn't pick an HTTP client for you. Bring the PSR-18 client your application already uses (Symfony's, Guzzle, whatever your framework has), or let it find one that's installed.

It doesn't do async or concurrent requests yet. Saloon does, on top of Guzzle, so if you need a pool of parallel requests today, Saloon is the better choice. Here's how the two compare otherwise:

| | Saloon 4 | SDK Toolbox |
|---|---|---|
| HTTP client | Guzzle, required | any PSR-18 client |
| What `send()` returns | `mixed` | the request's own type, checked by PHPStan |
| Retries | every 4xx and 5xx | only what RFC 9110 says is safe to repeat |
| Errors | a class per status | a class per status, plus RFC 9457 problems |
| Caching | a fixed lifetime | RFC 9111: ETags, 304s, `Vary` |
| Async and pools | yes | not yet |

## Getting started

```bash
composer require juststeveking/sdk-toolbox
```

You'll need PHP 8.3 or newer, and a PSR-18 client with PSR-17 factories if your application doesn't already have them (`symfony/http-client` and `nyholm/psr7` will do). Two optional extras:

- `cuyz/valinor`, for mapping responses onto your own classes;
- `open-telemetry/api`, for tracing.

From there, [Getting started](docs/getting-started.md) walks through building a complete SDK, test and all.

It's at 0.x and not on Packagist yet, so expect the odd change before 1.0.

## The docs

- [Getting started](docs/getting-started.md): a complete SDK, start to finish
- [Requests and bodies](docs/requests-and-bodies.md), [Errors](docs/errors.md) and [Authentication](docs/authentication.md)
- [Results and pagination](docs/results-and-pagination.md) and [Reliability](docs/reliability.md): retries, idempotency and rate limits
- [HTTP semantics](docs/http-semantics.md): caching, ETags, deprecation and long-running operations
- [Observability](docs/observability.md), [Middleware](docs/middleware.md) and [Testing](docs/testing.md)
- [Integrity and webhooks](docs/integrity-and-webhooks.md) and [Security](docs/security.md)
- [Standards](docs/standards.md): every RFC and draft it follows, and how far
- [Porting an existing SDK](docs/porting.md): what moving einvoicing.dev's SDK across took

## If you build with an AI agent

The package ships a skill that teaches an agent to build SDKs the toolbox's way, in `resources/boost/skills/sdk-toolbox-development`. Laravel Boost picks it up on its own; anywhere else, copy that directory into your agent's skills directory (`.claude/skills/` for Claude Code). If you're working on the toolbox itself, [AGENTS.md](AGENTS.md) is for you.

## Working on it

```bash
composer test      # Pest
composer analyse   # PHPStan, at max
composer lint      # Pint
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
