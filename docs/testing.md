# Testing

Everything in `JustSteveKing\SdkToolbox\Testing` is for an SDK's own test suite, and for the test suites of applications that use it.

## The fake client

`FakeClient` is a PSR-18 client that answers from a queue, in order, and records what it was sent:

```php
$http = new FakeClient(
    FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']),
    FakeResponse::problem(404, title: 'Not Found', detail: 'No project prj_9.'),
    FakeResponse::networkError('Could not resolve host'),
);

$acme = new Acme('test-token', $http);
```

| Response | Gives |
|---|---|
| `FakeResponse::json($data, $status, $headers)` | a JSON response |
| `FakeResponse::problem($status, type:, title:, detail:, extensions:)` | an RFC 9457 `application/problem+json` response |
| `FakeResponse::text($body, $status, $headers)` | plain text |
| `FakeResponse::empty($status, $headers)` | no body, 204 by default |
| `FakeResponse::networkError($message)` | a PSR-18 network failure, thrown when reached |

A closure works too: `fn (RequestInterface $request) => ...` answers based on what was sent. `push()` adds more answers later.

When the queue runs out, the next request throws `StrayRequestException`. It deliberately isn't a PSR-18 exception: the connector would wrap that as a transport error, and an SDK might retry past it, so a test could pass for the wrong reason.

### Assertions

| Assertion | Passes when |
|---|---|
| `assertSent(fn (RequestInterface $r) => ...)` | some request matches |
| `assertNotSent(fn ...)` | no request matches |
| `assertSentTo('POST', '/v2/projects')` | a request with that method and path went |
| `assertSentJson(['meta' => ['ref' => 'x']])` | a JSON body contained all of that (a subset match) |
| `assertSentInOrder(['POST /v2/charges', 'GET /v2/charges/ch_1'])` | those went in that order, others allowed between |
| `assertSentCount(2)`, `assertNothingSent()` | that many were sent |
| `assertQueueEmpty()` | every queued answer was used |

They report through PHPUnit (and so Pest) when it's installed, and throw `AssertionError` otherwise. `sent()` and `last()` give you the PSR-7 requests themselves.

### Retries in tests

Retries are on by default, so a test that queues one 503 for a GET will see the request sent again. Either queue the answers a retry will use, or build the connector under test with `retry: RetryPolicy::none()` when the test is about a single answer.

## Time

Retries, the throttle, rate-limit resets, OAuth expiry, webhook tolerance and the device flow all ask a PSR-20 clock what time it is, and a `Sleeper` to wait. `FakeTime` is both, and sleeping just moves its clock on:

```php
$time = new FakeTime('2026-11-16 09:00:00 UTC');
$acme = new Connector('https://api.acme.test', http: $http, clock: $time, sleeper: $time);

$acme->send(new GetProject('prj_1'));   // a 503 with Retry-After: 2, then a 200

expect($time->sleeps())->toBe([2.0]);
```

`advance($seconds)` moves the clock without a sleep, for expiring tokens or cache entries. `ArrayCache` is an in-memory PSR-16 cache that runs on the same clock, for testing the HTTP cache, token stores and rate-limit stores.

## Recorded fixtures

Hand-written fake responses drift from the real API. `Recorder` records real responses once and replays them after:

```php
$http = new Recorder(__DIR__.'/fixtures', 'projects/list', RecordMode::fromEnvironment());
$acme = new Acme(getenv('ACME_TOKEN') ?: 'not-needed-for-replay', $http);
```

| Mode | Does |
|---|---|
| `RecordMode::Replay` (the default) | answers from the fixture; a missing fixture fails the test |
| `RecordMode::Record` | sends to the real API and writes the fixture afresh |
| `RecordMode::Missing` | replays what exists, records what doesn't |

`fromEnvironment()` reads `SDK_FIXTURES`, so `SDK_FIXTURES=record vendor/bin/pest` re-records, and CI, with nothing set, replays.

- **Safe to commit.** Fixtures are JSON, written with credentials already masked: sensitive headers and query parameters, plus secret-looking keys in JSON and form bodies (`access_token`, `refresh_token`, `client_secret`, `password`, `api_key` and others) in both directions.
- **Safe to name.** A fixture name is letters, digits, `-`, `_` and `/`, and can't resolve outside the directory. That's the path traversal Saloon fixed in v4.
- **Strict on replay.** Requests have to arrive in the recorded order, with the recorded method and path. Anything else throws `FixtureMismatchException`, saying what differed, rather than quietly answering the wrong request.
- **The query too, when you ask.** Some APIs answer every call at one path, such as `GET /search.json?engine=...`, so method and path can't tell one request from another. `new Recorder($dir, 'google/coffee', $mode, matchQuery: true)` makes the query match as well, in any order. Parameters the Redactor masks, such as an API key, are left out of the comparison, so a test replaying with a fake key still matches.

## Deprecated endpoints

Build the connector in your SDK's tests with `deprecations: Deprecations::strict()`. When the API starts marking an endpoint deprecated, a test that hits it fails, and you hear about it before your users do.

## Type assertions

The toolbox's generics are what make `send(new GetProject($id))` a `Project` to PHPStan. A file of PHPStan type assertions keeps that true as your SDK changes:

```php
use function PHPStan\Testing\assertType;

$acme = new Acme('token', new FakeClient);

assertType(Project::class, $acme->send(new GetProject('prj_1')));
assertType('list<'.Member::class.'>', $acme->send(new ListMembers));
assertType('JustSteveKing\SdkToolbox\Pagination\Paginator<'.Member::class.'>', $acme->paginate(new ListMembers, new LinkPagination));
```

Put it in a directory PHPStan analyses but your test runner doesn't. A request whose `@implements Request<...>` is wrong then fails `phpstan analyse`.
