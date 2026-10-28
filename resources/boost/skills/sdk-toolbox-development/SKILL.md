---
name: sdk-toolbox-development
description: Build or change a PHP API SDK with juststeveking/sdk-toolbox. Use when writing a Connector or Request class, sending or paginating requests, mapping API errors to exceptions, adding authentication or OAuth, configuring retries, Idempotency-Key, rate limits, caching or ETags, logging or tracing, verifying webhooks or signatures, or testing an SDK with FakeClient, FakeTime or recorded fixtures.
---

# SDK Toolbox Development

## When to use this skill

Use it when you work on code that requires `juststeveking/sdk-toolbox`: an SDK's connector, its request classes, its resources, its exceptions or its tests. The toolbox implements the HTTP standards a client should honour. Use its feature rather than inventing a retry loop, a header, a pagination helper or a fake HTTP client.

## The shape of an SDK

- **One connector** extends `JustSteveKing\SdkToolbox\Connector` and passes the API's base URI, authenticator and user agent to the parent constructor. It takes an optional `?ClientInterface $http` so applications and tests can pass their own client.
- **One request class per endpoint** implements `Requests\Request` with an `@implements Request<ResultType>` docblock. That docblock is what makes `send()` return the right type to PHPStan: never leave it out.
- **Resource classes** group requests (`$sdk->projects()->get($id)`). They only call `$this->connector->send(new SomeRequest(...))`.
- **Result types** are your own `final readonly` classes, built in `result()` by hand or with `$response->map(Class::class, at: 'data')`.

## Rules

1. **Put caller input into paths with `rawurlencode()`:** `'/projects/'.rawurlencode($this->id)`. Never put a query string or an absolute URL in `path()`; the connector refuses both. Query parameters go in `query()` (`HasQuery`), and `null` values are left out.
2. **Never check status codes in `result()`.** It only runs for 2xx and 3xx; `send()` has already thrown for 4xx and 5xx.
3. **Map an API's error format with an `ExceptionMapper`,** not by catching and rethrowing in every resource. Use `ProblemTypes` for RFC 9457 APIs.
4. **Don't write retry loops.** Retries are on by default and safe. Implement `Idempotent` on POSTs when the API supports Idempotency-Key, so they can be retried too.
5. **Don't pass credentials anywhere but an authenticator.** Never put a key in `headers()` or `query()` yourself: the authenticator's names are what the redactor masks.
6. **Tests use `Testing\FakeClient`,** never a mocked PSR-18 interface. Either pass `retry: RetryPolicy::none()`, or queue the answers a retry will consume and pass `Testing\FakeTime` as `clock:` and `sleeper:`.
7. **Keep the PHP floor of the SDK you're in.** The toolbox runs on PHP 8.3; if the SDK does too, don't use 8.4 syntax such as `new Foo()->bar()` without parentheses.

## Choose the reference for the task

| Task | Read |
|---|---|
| A new endpoint: the request class, its query, headers or body (JSON, form, multipart, Merge Patch, JSON Patch) | [references/requests.md](references/requests.md) |
| Errors and exceptions; authentication, API keys and OAuth | [references/errors-and-auth.md](references/errors-and-auth.md) |
| Typed results, or a list endpoint that pages | [references/results-and-pagination.md](references/results-and-pagination.md) |
| Retries, Idempotency-Key, rate limits, the throttle | [references/reliability.md](references/reliability.md) |
| Caching, ETags and If-Match, Deprecation, Prefer and 202 polling, logging and tracing, signatures and webhooks | [references/http-features.md](references/http-features.md) |
| Writing or fixing tests | [references/testing.md](references/testing.md) |

## Checks before you finish

- `vendor/bin/phpstan analyse` passes. A request whose `@implements` is wrong shows up here.
- The tests pass with nothing reaching the network: a `StrayRequestException` means a request had no fake answer.
- No credential appears in a fixture, an assertion message or a log line you added.
