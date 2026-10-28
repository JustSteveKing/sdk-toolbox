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
  - RFC 9110 `Retry-After`, Idempotency-Key, RateLimit headers
  - RFC 8288 `Link` pagination
  - OAuth 2.0: RFC 6749, 6750, 7636 (PKCE), 8628 (device flow), 9700
  - RFC 9111 caching, RFC 9110 conditional requests, RFC 9745 Deprecation,
    RFC 8594 Sunset, RFC 7240 Prefer
  - PSR-3 logging, PSR-14 events, W3C Trace Context, OpenTelemetry spans
  - RFC 9530 Content-Digest, RFC 9421 HTTP Message Signatures, Standard
    Webhooks

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

## Authentication

`BearerToken`, `BasicAuth`, `HeaderKey` and `QueryKey` cover static
credentials. For OAuth 2.0, describe the client once:

```php
$oauth = new OAuthClient(
    tokenEndpoint: 'https://auth.acme.test/oauth/token',
    clientId: $id,
    clientSecret: $secret,                 // null for a public client (CLI, mobile)
    authorizationEndpoint: 'https://auth.acme.test/oauth/authorize',
);
```

Then pick a grant:

- **Server to server:** `auth: new ClientCredentials($oauth, ['projects:read'])`.
  It fetches a token when needed and caches it until shortly before it
  expires.
- **For a user (authorisation code with PKCE):**
  - `$flow->start()` gives the URL to send the user to, plus a `state` and
    a PKCE verifier to keep.
  - `$flow->exchange($started, $code, $state)` checks the state and returns
    the token.
  - Then `auth: new RefreshingToken($oauth, "user:{$id}", $store)` keeps
    that token fresh from then on.
- **For a CLI (device flow, RFC 8628):** `$flow->start()` gives the code
  and URL to show the user; `$flow->poll($code)` waits for them.

Following the RFCs:

- **Rejected tokens:** a 401 `invalid_token` gets new credentials and one
  retry (RFC 6750 §3.1). A bare 401 counts too, since many APIs send one.
- **Client secrets** are form-encoded before Basic encoding (RFC 6749
  §2.3.1).
- **Refresh tokens:** a new one replaces the old (§6).
- **Token types:** only Bearer tokens are used (§7.1).
- **PKCE** is S256 only.
- **Device polling** waits the interval, adds 5s on `slow_down` and
  doubles after a connection failure.
- **TLS:** every OAuth endpoint must use it.

Tokens live in a `TokenStore`: in memory, or any PSR-16 cache through
`Psr16TokenStore`, which stores plain arrays and never `unserialize()`s.
Refreshes run inside a `RefreshLock` and look in the store again once
they hold it. So ten workers that find the same expired token refresh it
once, and refresh-token rotation survives. Adapt your framework's lock;
the interface's docblock has a Symfony example.

## Typed results

`$response->map(Project::class)` and `$response->mapList(Member::class, at: 'data')`
turn JSON into your own readonly classes, through a `Hydrator`.

The toolbox ships `ValinorHydrator`. Install `cuyz/valinor` and it's used
automatically. It is:

- strict about types: a string where an int belongs is an error, not a cast;
- relaxed about extra fields, so a field the API adds later breaks nothing;
- able to map `snake_case` keys to `camelCase` properties.

A response that doesn't fit throws `HydrationException`, which names the
field that broke. Pass any other `Hydrator` to the connector to use
something else.

## Pagination

```php
foreach ($acme->paginate(new ListMembers, new CursorPagination('meta.next_cursor')) as $member) {
    // every member, a page at a time
}
```

The same request returns one page from `send()` and every page from
`paginate()`. Its `result()` reads a page into items, and a strategy works
out the next request:

| Strategy | For |
|---|---|
| `LinkPagination` | `Link: <...>; rel="next"` (RFC 8288), as GitHub does it |
| `CursorPagination` | a cursor in the body, sent back as a query parameter |
| `StartingAfterPagination` | Stripe's `has_more` and `starting_after` |
| `OffsetPagination` | `offset` and `limit`; a short page is the last |
| `PagePagination` | `page`, stopping on an empty or short page, or at the last page the body names |

Pagination is safe and bounded:

- Only one page is in memory at a time. A test walks 100,000 items and
  checks memory stays flat.
- Every page goes through retries, the throttle and your middleware.
- A `next` link that leaves the base URI is refused.
- A page that points back at itself throws rather than looping, and
  `maxPages` caps longer cycles.
- `take(n)` fetches only the pages it needs, and `pages()` gives each page
  with its response.

PHPStan knows the item type throughout: `paginate(new ListMembers, ...)` is
a `Paginator<Member>`.

## Retries

Retries are on by default, and they only repeat what RFC 9110 says is safe
to repeat:

| Outcome | Retried for |
|---|---|
| 408, 425, 429, 503: the server didn't process it | any method |
| 500, 502, 504, or no response at all | GET, HEAD, OPTIONS, PUT, DELETE, and any request with an Idempotency-Key |
| 409, "still in progress" in the Idempotency-Key draft | requests with an Idempotency-Key |
| anything else (400, 401, 404, 422...) | never |

Each wait honours `Retry-After` (seconds or any HTTP-date form). Without
it, waits back off exponentially with jitter, up to a cap. A `Retry-After`
longer than `maxRetryAfter` gives up instead of sleeping, and an optional
deadline caps the whole sequence, waits included. A body that can't be
rewound is never sent twice.

```php
retry: RetryPolicy::standard()->withMaxAttempts(5)->withDeadline(30.0),
retry: RetryPolicy::none(),
```

When the retries run out, the exception carries `$e->retryAfter` if the
server sent one. Pass any PSR-20 `clock` and a `sleeper`;
`Testing\FakeTime` is both, and records every wait.

### Idempotency-Key (experimental)

Implement `Idempotent` on a request and the connector sends an
Idempotency-Key. The key is a random UUID unless the request returns its
own, and it stays the same across every retry of that send. That's what
makes retrying a POST safe. The draft's format is a quoted string; pass
`idempotencyKeys: IdempotencyKey::Bare` for APIs, Stripe among them, that
take a bare token. Experimental: the draft is at -08 and its retry section
is still open.

## Rate limits

`$response->rateLimit()` returns the most restrictive policy the response
reported: remaining quota, limit, seconds until reset and window. It reads:

- the IETF `RateLimit` and `RateLimit-Policy` fields (experimental: draft
  -11 expires on 2026-11-24);
- failing those, `X-RateLimit-*` or `RateLimit-Limit/Remaining/Reset`.
  Resets in seconds, Unix timestamps (GitHub) and epoch milliseconds are
  all understood.

Malformed fields are ignored rather than half-read.

To stop sending when the quota is spent, rather than collecting 429s,
give the connector a `Throttle`:

```php
throttle: new Throttle(maxWait: 30.0),
throttle: new Throttle(new Psr16RateLimitStore($cache)), // shared between processes
```

The next request to that host waits for the reset. If the reset is
further off than `maxWait`, the throttle throws
`RateLimitExceededException` without sending. A 429's `Retry-After` wins
over the RateLimit fields, as the draft says.

## HTTP caching

Give the connector a PSR-16 store and it caches the way RFC 9111 says a
private cache should:

```php
cache: $psr16Cache,
```

- **Reuse:** a fresh response is served without a request, with an `Age`
  header. A stale one, or one marked `no-cache`, is revalidated with
  `If-None-Match` and `If-Modified-Since`, and a 304 brings back the stored
  body.
- **Storage:** 200 and 203 responses to GET that can be reused, either
  because they have `max-age` or `Expires`, or because they have a
  validator.
- **Never stored:**
  - `no-store`, from either side;
  - `Vary: *`;
  - 429s;
  - anything over 1MB.
- **Separation:** entries are keyed on the request's credentials, so users
  never see each other's responses, even through a shared Redis. `Vary` is
  honoured.
- **Invalidation:** a successful POST, PUT, PATCH or DELETE drops the
  entry for its URI.

There's no heuristic freshness. A response without `max-age` or `Expires`
is always revalidated before reuse. Entries are plain arrays, never
serialized objects.

## Conditional requests

Read a resource, keep its ETag, and send the change only if nobody else
changed it in between:

```php
$etag = $acme->response(new GetProject($id))->etag();

// in UpdateProject, which implements Conditional:
public function precondition(): ?Precondition
{
    return Precondition::ifMatch($this->etag);
}
```

A 412 throws `PreconditionFailedException`, and a 428 (the API requires a
precondition) throws `PreconditionRequiredException`. Weak tags are
refused in `If-Match`, since strong comparison means they could never
match. `Precondition` also offers:

- `ifNoneMatchAny()`: create, but never overwrite;
- `ifExists()`;
- `ifUnmodifiedSince()`.

## Deprecation and Sunset

`$response->deprecation()` reads:

- `Deprecation` (RFC 9745's `@timestamp`, or the undated `?1` from older
  drafts);
- `Sunset` (RFC 8594);
- their `Link` relations.

To hear about them as they happen:

```php
deprecations: new Deprecations(fn (DeprecationNotice $n) => $logger->warning($n->describe())),
deprecations: Deprecations::strict(),   // fail an SDK's own test suite instead
```

The handler runs once per endpoint per process. Nothing about the request
changes: both headers are hints.

## Preferences and long-running operations

A request that implements `HasPreferences` sends `Prefer` (RFC 7240):

```php
Preferences::none()->returnMinimal()->respondAsync()->wait(10)
```

`$response->preferenceApplied()` says which preferences the server honoured.

`$connector->await($request)` handles the 202 pattern:

1. A `202 Accepted` with a `Location` is polled with GET, waiting for
   `Retry-After` (or `interval`) between polls.
2. A 303 from the monitor is followed to the result; a 200 is the result.
3. The original request's `result()` reads it.

A synchronous answer comes straight back. Monitors outside the base URI
are refused. When `timeout` is reached, `OperationTimeoutException`
carries the monitor URI so you can check again later.

## Logging, events and tracing

```php
logger: $psr3Logger,              // every event, as a log line
events: $psr14Dispatcher,         // every event, as an object
tracing: new OpenTelemetrySpans,  // or new TraceContext(fn () => [$traceparent, $tracestate])
```

| Event | Level | When |
|---|---|---|
| `RequestSending` | debug | each attempt, as it goes to the client |
| `ResponseReceived` | debug, warning for 5xx | each attempt that got a response, with its duration |
| `RequestFailed` | warning | each attempt that got no response |
| `RetryScheduled` | info | the retry policy is trying again, and when |
| `RateLimitWaiting` | warning | the throttle is holding a request |
| `CredentialsRefreshed` | info | the API rejected the credentials; renewed, retrying once |
| `CacheHit` | debug | served from the HTTP cache, fresh or after a 304 |
| `DeprecationNoticed` | warning | Deprecation or Sunset, once per endpoint |

Events carry redacted request snapshots, never the PSR-7 request, so
neither a listener nor a log line can leak credentials.

`OpenTelemetrySpans` records a client span per attempt, following the
stable HTTP semantic conventions:

- **Attributes:** method, server address and port, a redacted `url.full`,
  the status code, `http.request.resend_count` on retries, and
  `error.type`.
- **Name:** `{method}`, never the raw path.
- **Status:** Error for 4xx, 5xx and network failures.
- **Propagation:** the span's own context goes out as `traceparent`.

It needs `open-telemetry/api` and uses OpenTelemetry's globals unless you
pass a tracer provider and propagator.

`TraceContext` is the dependency-free option. It joins the trace you give
it, with a new parent-id for every request as the W3C Recommendation
requires, or starts one when there isn't one.

## Middleware

```php
interface Middleware
{
    public function handle(RequestInterface $request, Next $next): ResponseInterface;
}
```

The order is:

1. retries
2. the throttle
3. the deprecation watcher
4. your middleware, in order
5. authentication (including the one retry after a rejected token)
6. the HTTP cache
7. instrumentation and tracing, per attempt
8. the HTTP client

The cache comes after authentication so it can key entries on
credentials. So every retry is throttled,
passes through your middleware and is authenticated afresh. Nothing before
authentication, a logger included, sees the credentials. The client can be any PSR-18 client, an HTTPlug
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

## Integrity and signatures

`new Digests` adds `Content-Digest` (RFC 9530, sha-256 or sha-512) to
every request with a body, and throws `DigestMismatchException` when a
response's content doesn't match its own. Only the Active algorithms are
supported. A response a client may have decompressed isn't checked.

`new SignRequests($key, ['@method', '@authority', '@path'])` signs
requests with RFC 9421 HTTP Message Signatures:

- **Keys:** `SigningKey::hmac()` or `SigningKey::ed25519()`, the latter
  taking a seed or a PEM.
- **Coverage:** it covers `content-digest` too when `Digests` runs first,
  so the body is signed.
- **Correctness:** the tests reproduce the RFC's Appendix B signatures
  byte for byte.

`MessageVerifier` checks signatures against the requirements RFC 9421
leaves to the application:

- which components must be covered;
- a maximum age, with `expires` enforced;
- an optional `tag`;
- a key lookup by `keyid`, so an unknown key fails.

A stated `alg` must match the key. Component parameters (`;sf`, `;key`,
`;req`...) are refused rather than checked against the wrong base.

## Webhooks

Every verifier takes the PSR-7 request as it arrived and throws
`InvalidSignatureException`:

```php
new StandardWebhooks('whsec_...')        // v1 HMAC, or 'whpk_...' for v1a Ed25519
new HmacWebhook($secret, header: 'X-Hub-Signature-256', prefix: 'sha256=')   // GitHub and similar
new SignedWebhook($messageVerifier)      // RFC 9421, requiring content-digest
```

- **`StandardWebhooks`:**
  - checks the timestamp against a tolerance;
  - accepts any one of several signatures, for key rotation;
  - `check()` returns the `webhook-id` to deduplicate on;
  - `StandardWebhooks::sign()` makes signatures for testing your own
    handlers.
- **Test vectors:** both verifiers are tested against the vectors their
  specs publish (the Standard Webhooks PHP library's, and GitHub's docs).

## Recorded fixtures

```php
$http = new Recorder(__DIR__.'/fixtures', 'projects/list', RecordMode::fromEnvironment());
```

- **Replay** is the default. Run with `SDK_FIXTURES=record` to hit the
  real API and write the fixture, or `missing` to record only what isn't
  there yet.
- **Credentials are masked before writing,** so fixtures are safe to
  commit: sensitive headers and query parameters, plus `access_token`,
  `client_secret` and other secret-looking keys in JSON and form bodies.
- **Fixture names can't leave the directory.**
- **Replay is strict:** requests must arrive in the recorded order with
  the recorded method and path, or `FixtureMismatchException` says what
  differed.

`FakeClient` also has `assertSentTo()`, `assertSentJson()` (a subset
match) and `assertSentInOrder()`.

## Development

```bash
composer test      # Pest: unit, RFC conformance, feature and arch suites
composer analyse   # PHPStan max, including type assertions on the fixture SDK
composer lint      # Pint
```

## Licence

MIT. See [LICENSE.md](LICENSE.md).
