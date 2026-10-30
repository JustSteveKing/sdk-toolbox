# HTTP semantics

## HTTP caching

Give the connector a PSR-16 cache and it caches responses the way RFC 9111 says a private cache should:

```php
parent::__construct(baseUri: 'https://api.acme.test', cache: $psr16Cache);
```

- **Fresh responses** are returned without a request, with an `Age` header.
- **Stale responses,** and those marked `no-cache`, are revalidated: the request goes out with `If-None-Match` and `If-Modified-Since`, and a 304 refreshes the stored headers and returns the stored body.
- **What's stored:** 200 and 203 responses to GET that can be reused, either because they have `max-age` or `Expires`, or because they have a validator (`ETag`, `Last-Modified`).
- **Never stored:**
  - `no-store`, from either side;
  - `Vary: *`;
  - 429s (RFC 6585);
  - bodies over 1MB.
- **`Vary`** is honoured: a stored response is only used for a request that matches it on every header it names.
- **Writes invalidate.** A successful POST, PUT, PATCH or DELETE removes the stored response for its URI.
- **Users stay apart.** Entries are keyed on every credential the request carries, so one user never gets another's response, even through a shared Redis.

There's no heuristic freshness: a response without `max-age` or `Expires` is always revalidated before it's reused. `s-maxage` and `public` are for shared caches and are ignored. Entries are stored as plain arrays, never serialized objects.

## Conditional requests

The lost-update problem: two people read a resource, both change it, and the second save silently overwrites the first. Read the ETag and send the change with `If-Match`:

```php
$etag = $acme->response(new GetProject($id))->etag();

$acme->send(new RenameProject($id, 'Production', $etag));
```

```php
final readonly class RenameProject implements Conditional, HasBody, Request
{
    public function __construct(private string $id, private string $name, private ?EntityTag $etag) {}

    public function precondition(): ?Precondition
    {
        return $this->etag === null ? null : Precondition::ifMatch($this->etag);
    }

    // method(), path(), body(), result()...
}
```

If somebody changed it in between, the API answers 412 and `send()` throws `PreconditionFailedException`. A 428 means the API insists on a precondition, and throws `PreconditionRequiredException`.

| Precondition | Means |
|---|---|
| `Precondition::ifMatch($etag, ...)` | only if it's still this version |
| `Precondition::ifExists()` | only if it exists (`If-Match: *`) |
| `Precondition::ifNoneMatchAny()` | only if it doesn't exist yet: create, never overwrite |
| `Precondition::ifUnmodifiedSince($date)` | only if unchanged since |

`If-Match` uses strong comparison (RFC 9110 §13.1.1), so a weak tag (`W/"..."`) can never match. It's refused up front rather than failing every time.

## Deprecation and Sunset

`$response->deprecation()` reads what an API says about the end of an endpoint:

- `Deprecation` (RFC 9745's `@1688169599`, or the undated `?1` older drafts used);
- `Sunset` (RFC 8594);
- the `deprecation` and `sunset` link relations.

To hear about it as it happens:

```php
deprecations: new Deprecations(fn (DeprecationNotice $notice) => $logger->warning($notice->describe())),
deprecations: new Deprecations,           // only logged and dispatched (see Observability)
deprecations: Deprecations::strict(),     // throws: for an SDK's own test suite
```

The handler runs once per endpoint per process, so a loop over a deprecated endpoint doesn't flood a log. Both headers are hints: nothing about the request changes.

`Deprecations::strict()` is the useful one for SDK authors. Run your tests against recorded fixtures with it, and an endpoint the API has deprecated fails your build, not a user's.

## Preferences and 202 polling

A request that implements `HasPreferences` sends `Prefer` (RFC 7240):

```php
public function preferences(): Preferences
{
    return Preferences::none()->returnMinimal()->respondAsync()->wait(10);
}
```

`$response->preferenceApplied()` says which preferences the server honoured.

For operations that take a while, `await()` follows a `202 Accepted` to its result, the way api-toolbox's asynchronous operations answer:

```php
$export = $acme->await(new StartExport($filters), timeout: 300.0, interval: 2.0);
```

1. A `202 Accepted` with a `Location` is an operation in progress, and `Location` is its status monitor (RFC 9110 section 15.3.3, RFC 7240 section 4.1).
2. The monitor is polled with GET, waiting for `Retry-After` (or `interval`) between polls, while it says the operation is still going: a 202, or a 200 that carries `Retry-After`. A monitor's own GET succeeds, so many answer 200 with the status and use `Retry-After` to say when to ask again; api-toolbox's does.
3. A `303 See Other` from the monitor is followed to the result; any other 200 is the result itself.
4. A 200 reporting `"status": "failed"` with an RFC 9457 problem in `error` throws `OperationFailedException`, carrying that problem and the monitor URI. A result that only has a status field is returned as it is.
5. Otherwise the original request's `result()` reads it.

`await()` follows the `303` itself, so give the connector a PSR-18 client that doesn't. Symfony's `Psr18Client` follows redirects by default: the monitor's `303` then never reaches `await()`, which gets the result as the monitor's answer instead, and a redirect to another host goes without the credentials. `new Psr18Client(HttpClient::create(['max_redirects' => 0]))` leaves redirects to the SDK. Guzzle's PSR-18 `sendRequest()` already does: it turns `allow_redirects` off for every request.

A request the API answers straight away comes back as normal. Every monitor and result URI has to be under the base URI. At the timeout, `OperationTimeoutException` carries the monitor URI, so you can look again later: the operation may still finish.

Not every API's asynchronous work is a 202 and a monitor. Some take a job, give you an ID, and leave you to look it up until a status field says it's finished. There's no standard for it, so `poll()` takes the API's own words for "done" and, optionally, "failed":

```php
$id = $acme->send(new SubmitReport($filters))->id;

$result = $acme->poll(
    new GetReport($id),
    done: fn (Response $response): bool => $response->get('status') === 'complete',
    failed: fn (Response $response): ?ProblemDetails => $response->get('status') === 'failed'
        ? new ProblemDetails(title: 'Report failed', detail: (string) $response->get('error'))
        : null,
    timeout: 120.0,
);
```

The first check goes straight away. Between checks it waits as long as `Retry-After` says, or `interval`. `done` returning true gives you the check's `result()`; `failed` returning a problem throws `OperationFailedException` with it; anything else means "still running", until `timeout` throws `OperationTimeoutException`. Both carry the check's URI, taken before authentication, so credentials are never in it. An error status throws as it would from `send()`.
