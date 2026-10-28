# SDK Toolbox

Build PHP SDKs on PSR-18, typed end to end, with the HTTP standards an API
client should honour.

It does the job Saloon does, with request objects, a connector, auth and
fakes, under different rules:

- **PSR-18 and PSR-17 only.** No HTTP client in `require`. Bring the one your
  application has, or let `php-http/discovery` find it.
- **Typed end to end.** A `Request<T>` comes back from `send()` as a `T`, to
  PHPStan as well as at runtime. The package runs at PHPStan max with no
  baseline.
- **No magic.** No trait booting, no static state, no mutable public
  config. Connectors are immutable, and their `with*()` methods are marked
  `#[\NoDiscard]`.
- **Secure by default.**
  - Request paths can't leave the base URI.
  - Credentials aren't sent without TLS unless you say so.
  - Exceptions keep a redacted snapshot of the request, never the request.
- **A published standard wherever one exists:**
  - RFC 9457 errors, RFC 9110 method semantics
  - RFC 7396 Merge Patch, RFC 6902 JSON Patch, RFC 7578 multipart
  - more to come: retries, rate limits, pagination, caching, deprecation

Requires PHP 8.5. It's at 0.x and not on Packagist yet.

## An SDK in four classes

```php
use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;

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
        return Project::fromArray($response->json());
    }
}

$project = new Acme($token)->send(new GetProject('prj_1')); // Project
```

A request adds a query, headers or a body by implementing `HasQuery`,
`HasHeaders` or `HasBody`. The bodies are:

- `JsonBody`, `FormBody` and `RawBody`
- `Multipart`, made of `Part`s, which streams files rather than loading them whole
- `MergePatch`, including `MergePatch::diff($before, $after)`
- `JsonPatch`

`send()` throws for a 4xx or 5xx. `response()` returns the response as it
came, status and all.

## Errors

Every exception implements `SdkException`.

- A 4xx or 5xx throws an `HttpException` subclass. It is chosen by status
  (`NotFoundException`, `UnprocessableContentException`,
  `TooManyRequestsException` and so on), and carries the RFC 9457 problem
  details when the body had them.
- To map an API's own problem types to your SDK's exceptions:

```php
exceptions: new ProblemTypes([
    'https://api.acme.test/problems/out-of-credit' => OutOfCredit::class,
]),
```

- No response at all is a `TransportException`.

## Middleware

```php
interface Middleware
{
    public function handle(RequestInterface $request, Next $next): ResponseInterface;
}
```

Middleware runs in order, then authentication, then the HTTP client.
Authentication runs last, so nothing earlier, a logger included, sees the
credentials. The client can be any PSR-18 client, an HTTPlug
`PluginClient` included.

## Testing

```php
$http = new FakeClient(
    FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']),
    FakeResponse::problem(404, title: 'Not Found'),
    FakeResponse::networkError(),
);

$acme = new Acme('token', $http);

$http->assertSent(fn (RequestInterface $r) => $r->getUri()->getPath() === '/v2/projects/prj_1');
```

When the queue runs out, the next request throws `StrayRequestException`,
which a connector never mistakes for a network error.

## Development

```bash
composer test      # Pest: unit, RFC conformance, feature and arch suites
composer analyse   # PHPStan max, including type assertions on the fixture SDK
composer lint      # Pint
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
