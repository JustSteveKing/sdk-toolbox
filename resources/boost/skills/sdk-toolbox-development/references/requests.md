# Requests

```php
use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Project>
 */
final readonly class UpdateProject implements HasBody, HasQuery, Request
{
    public function __construct(
        private string $id,
        private string $name,
        private bool $notify = false,
    ) {}

    public function method(): Method
    {
        return Method::Patch;
    }

    public function path(): string
    {
        return '/projects/'.rawurlencode($this->id);
    }

    public function query(): array
    {
        return ['notify' => $this->notify];   // sent as notify=true or notify=false
    }

    public function body(): Body
    {
        return new JsonBody(['name' => $this->name]);
    }

    public function result(Response $response): Project
    {
        return Project::fromArray($response->json());
    }
}
```

## Optional interfaces

| Interface | Method | For |
|---|---|---|
| `Requests\HasQuery` | `query(): array<string, mixed>` | query parameters; `null` is left out, booleans are `true`/`false`, backed enums send their value |
| `Requests\HasHeaders` | `headers(): array<string, string\|list<string>>` | per-request headers; they replace connector defaults of the same name |
| `Requests\HasBody` | `body(): Body` | a body; its Content-Type is set for you |
| `Requests\Idempotent` | `idempotencyKey(): ?string` | an Idempotency-Key; return null for a random UUID |
| `Requests\Conditional` | `precondition(): ?Precondition` | `If-Match` and friends |
| `Requests\HasPreferences` | `preferences(): Preferences` | a `Prefer` header |

Lists in a query are sent `ids[0]=1&ids[1]=2` unless the connector is built with `queryLists: Http\ListFormat::Brackets` (`ids[]=1&ids[]=2`) or `ListFormat::Repeated` (`ids=1&ids=2`). Check which the API reads. Keyed arrays are always `filter[status]=open`.

## Bodies

| Body | Use for |
|---|---|
| `new Bodies\JsonBody($array)` | JSON; an empty array is sent as `{}` |
| `new Bodies\FormBody($fields)` | `application/x-www-form-urlencoded`; `new FormBody($fields, ListFormat::Brackets)` for `ids[]=1` lists |
| `new Bodies\RawBody($stringOrStream, 'application/xml')` | anything else |
| `new Bodies\Multipart(Part::field('name', 'v'), Part::file('file', $resource, 'a.pdf', 'application/pdf'))` | uploads; files are streams, resources or strings, never paths |
| `Bodies\MergePatch::diff($before, $after)` | RFC 7396 partial updates; throws if a value changes to null, which Merge Patch can't express |
| `Bodies\JsonPatch::new()->test('/v', 3)->replace('/name', 'x')` | RFC 6902 operations; put a `test` first to make it conditional |

## The base URI

Paths are appended to the connector's base URI (`/projects` under `https://api.test/v2` is `https://api.test/v2/projects`). `Errors\UnsafeUriException` is thrown, and nothing sent, for:

- an absolute URL or `//host`;
- `..` segments, encoded or not;
- `?` or `#` in the path;
- an invalid URI, such as an unencoded space.

For an upload to a pre-signed URL on another host, use `$connector->sendUnauthenticated($url, $request)`: an absolute `https` URI (plain `http` only on an `insecure` connector), sent as given, with no authenticator, connector headers or middleware, only the request's own headers and the User-Agent. Retries and events still apply, with the URI redacted. It returns the `Http\Response` whatever its status, like `response()`.

## Sending

- `send($request)` returns `result()`, and throws for 4xx and 5xx.
- `response($request)` returns the `Http\Response` whatever its status.
- `$response->json()` gives the decoded body.
- `$response->get('meta.total')` reads a value by dotted path.
- `$response->psr()` is the PSR-7 response.
