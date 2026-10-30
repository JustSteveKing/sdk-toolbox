# Testing an SDK

```php
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

it('creates a project', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Docs'], 201));

    $project = (new Acme('test-token', $http))->projects()->create('Docs');

    expect($project->id)->toBe('prj_1');
    $http->assertSentTo('POST', '/v2/projects');
    $http->assertSentJson(['name' => 'Docs']);
});
```

## Fake responses

| | |
|---|---|
| `Testing\FakeResponse::json($data, $status, $headers)` | JSON |
| `Testing\FakeResponse::problem($status, type:, title:, detail:, extensions:)` | an RFC 9457 error |
| `Testing\FakeResponse::text($body, $status)`, `FakeResponse::empty(204)` | other bodies |
| `Testing\FakeResponse::networkError()` | a network failure |

A queue that runs out throws `Testing\StrayRequestException`. Fix the test; don't catch it.

## Assertions

- `assertSent(fn)` and `assertNotSent(fn)`;
- `assertSentTo($method, $path)`;
- `assertSentJson($subset)`;
- `assertSentInOrder(['POST /a', 'GET /b'])`;
- `assertSentCount($n)`, `assertNothingSent()`, `assertQueueEmpty()`.

`$http->last()` returns the last PSR-7 request.

## Retries and time

Retries are on by default. Either way, nothing in the suite should sleep for real:

- **One error, one attempt:** build the SDK with `retry: RetryPolicy::none()`.
- **Testing retries:** queue every answer and pass `$time = new Testing\FakeTime` as both `clock:` and `sleeper:`, then assert `$time->sleeps()`.

## Recorded fixtures

For responses copied from the real API, rather than hand-written:

```php
$http = new Testing\Recorder(__DIR__.'/fixtures', 'projects/list', Testing\RecordMode::fromEnvironment());
```

- **Replay is the default.** `SDK_FIXTURES=record` re-records against the real API.
- **Masking:** fixtures are written with credentials masked. Still read a new fixture before committing it, in case the API echoes something personal.
- **Strict replay:** a `Testing\FixtureMismatchException` means the SDK now sends something different from the recording. Re-record or fix the SDK. For an API that answers everything at one path, pass `matchQuery: true` to the `Recorder` so the query must match too (order and masked parameters ignored).

## Type checks

Keep a file PHPStan analyses but Pest doesn't, with `PHPStan\Testing\assertType()` calls such as `assertType(Project::class, $sdk->send(new GetProject('x')))`, so a wrong `@implements Request<...>` fails analysis.
