# Errors

Every exception the toolbox throws implements `SdkException`, so an SDK's callers can catch one type for everything.

| Exception | When |
|---|---|
| `HttpException` and its subclasses | The API answered 4xx or 5xx |
| `TransportException` | No response at all: DNS, TLS, refused connection, timeout |
| `InvalidResponseException` | A response that isn't the JSON a request expected |
| `HydrationException` | JSON that doesn't fit the class you mapped it to |
| `InvalidRequestException`, `UnsafeUriException` | A request that can't be built safely. Nothing was sent |
| `RateLimitExceededException` | The throttle refused to send ([Reliability](reliability.md#the-throttle)) |
| `OAuthException` | The authorisation server said no ([Authentication](authentication.md)) |

## HTTP errors

`send()` throws for any 4xx or 5xx. The class depends on the status:

| Status | Exception |
|---|---|
| 400 | `BadRequestException` |
| 401 | `UnauthorizedException` |
| 403 | `ForbiddenException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 410 | `GoneException` |
| 412 | `PreconditionFailedException` |
| 422 | `UnprocessableContentException` |
| 428 | `PreconditionRequiredException` |
| 429 | `TooManyRequestsException` |
| other 4xx | `ClientException` |
| 5xx | `ServerException` |

Every one of them carries:

- `$e->status`;
- `$e->response`: the PSR-7 response, body and all;
- `$e->request`: a **redacted snapshot** of the request (method, URI, headers), never the request itself, which would carry credentials;
- `$e->problem`: the RFC 9457 problem details, when the body had them;
- `$e->retryAfter`: seconds from `Retry-After`, when the server sent one.

The message names the request and the status, then the problem's detail or title: `GET /v2/projects/prj_9 returned 404 Not Found: No project prj_9.`

## Problem details

APIs that answer errors with `application/problem+json` (RFC 9457) get them parsed the way the RFC tells a client to:

```php
try {
    $acme->projects()->create('Docs');
} catch (UnprocessableContentException $e) {
    $e->problem?->type;                   // 'https://api.acme.test/problems/validation'
    $e->problem?->extension('errors');    // ['name' => ['Required.']]
}
```

- **`type`** is the problem's identifier. A relative one is resolved against the request URI (without its query, so a key can't leak into it). It's never fetched.
- **Mistyped members are ignored,** as section 3.1 says: a `status` of `"404"` is dropped, not trusted.
- **Everything else** is an extension, read with `extension()`.

Don't branch on `title` or `detail`. They're prose for humans, and they change.

## Mapping an API's own problems

An `ExceptionMapper` decides which exception a response becomes. `ProblemTypes` maps problem types to your SDK's exceptions, and falls back to the status mapping for anything else:

```php
parent::__construct(
    baseUri: 'https://api.acme.test',
    exceptions: new ProblemTypes([
        'https://api.acme.test/problems/out-of-credit' => OutOfCredit::class,
    ]),
);
```

```php
final class OutOfCredit extends ClientException
{
    public function balance(): int
    {
        $balance = $this->problem?->extension('balance');

        return is_int($balance) ? $balance : 0;
    }
}
```

The constructor of `HttpException` is final, so the mapper can always build your class the same way. To change the message, override `describe()`:

```php
protected function describe(Response $response, ?ProblemDetails $problem): string
{
    return $problem?->title ?? 'Acme returned an error.';
}
```

### APIs without RFC 9457

Plenty of APIs answer errors as `{"error": "..."}` or something of their own. Write an `ExceptionMapper` for them. It gets the `Response` and returns any `HttpException`, and can build a `ProblemDetails` from whatever the body holds:

```php
final readonly class AcmeErrors implements ExceptionMapper
{
    public function map(Response $response, Redactor $redactor): HttpException
    {
        $body = json_decode($response->body(), true);
        $message = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
        $problem = $message === null ? null : new ProblemDetails(title: $message, status: $response->status());

        return match ($response->status()) {
            401 => new UnauthorizedException($response, $problem, $redactor),
            default => (new StatusExceptions)->map($response, $redactor),
        };
    }
}
```

## Transport errors

When no response comes back, `TransportException` wraps the PSR-18 client's exception, which stays available as `getPrevious()`.

- **`$e->network`** is true for a network failure, as opposed to the client refusing the request itself.
- **Credentials in the message are masked:** HTTP clients often quote the full URL in their error, query string and all.
- **The previous exception's message isn't masked.** It's the client's own, kept because it's the useful part when debugging. Don't log it unfiltered.

A network failure on a safe request is retried before it's thrown: see [Reliability](reliability.md).
