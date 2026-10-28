## SDK Toolbox

This application builds or uses an API SDK on `juststeveking/sdk-toolbox`: a `Connector` subclass, one `Request<T>` class per endpoint, PSR-18 underneath.

- Load the `sdk-toolbox-development` skill before writing a request, an exception mapper, authentication, pagination or tests for the SDK.
- Request classes carry `@implements Request<ResultType>`, put caller input into paths with `rawurlencode()`, and never check status codes in `result()`.
- Retries, redaction, rate limits and OAuth refresh are the toolbox's job: don't write retry loops, log requests by hand, or put credentials anywhere but an authenticator.
- Tests use `JustSteveKing\SdkToolbox\Testing\FakeClient`, with `RetryPolicy::none()` or `FakeTime` so nothing sleeps.
