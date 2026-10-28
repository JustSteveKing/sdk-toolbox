# Errors and authentication

## Errors

Every exception implements `Errors\SdkException`. `send()` throws an `Errors\HttpException` subclass for 4xx and 5xx:

| Status | Class |
|---|---|
| 400, 401, 403, 404 | `BadRequestException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException` |
| 409, 410, 412 | `ConflictException`, `GoneException`, `PreconditionFailedException` |
| 422, 428, 429 | `UnprocessableContentException`, `PreconditionRequiredException`, `TooManyRequestsException` |
| other 4xx, 5xx | `ClientException`, `ServerException` |

Every one has:

- `->status`;
- `->response`, the PSR-7 response;
- `->request`, a redacted `RequestSnapshot`;
- `->problem`, an `Errors\ProblemDetails` or null;
- `->retryAfter`, in seconds or null.

No response at all is `Errors\TransportException`.

### Your SDK's own exceptions

- **For an RFC 9457 API,** map problem types:

  ```php
  exceptions: new ProblemTypes(['https://api.test/problems/out-of-credit' => OutOfCredit::class]),
  ```

- **For an API with its own error format** (`{"error": "..."}`), implement `Errors\ExceptionMapper::map(Response $response, Redactor $redactor): HttpException`. Build a `ProblemDetails` from the body yourself, and fall back to `(new StatusExceptions)->map($response, $redactor)`.

Rules for your exception classes:

- They extend `ClientException`, `ServerException` or `HttpException`.
- The constructor is final, so don't add one. To change the message, override `protected function describe(Response $response, ?ProblemDetails $problem): string`.
- Read extension members with `$this->problem?->extension('balance')`.
- Never branch on `title` or `detail`.

## Authentication

| Authenticator | For |
|---|---|
| `Auth\BearerToken($token)` | `Authorization: Bearer` |
| `Auth\BasicAuth($user, $password)` | HTTP Basic |
| `Auth\HeaderKey('X-Api-Key', $key)` | a key in a header; that header is masked everywhere |
| `Auth\QueryKey('api_key', $key)` | a key in the query, only when the API allows nothing else |

Pass it as `auth:` to the connector. A connector with credentials refuses an `http://` base URI unless `insecure: true` is passed; only do that for local servers.

### OAuth 2.0

```php
$oauth = new Auth\OAuth\OAuthClient(tokenEndpoint: '...', clientId: $id, clientSecret: $secret, authorizationEndpoint: '...');
```

Pass `scopeSeparator: ','` when the server lists scopes with commas, and `authentication: Auth\OAuth\ClientAuthentication::Post` when it wants the secret in the body.

| Grant | Use |
|---|---|
| `new Auth\OAuth\ClientCredentials($oauth, ['scope'])` | server to server; needs a client secret |
| `new Auth\OAuth\AuthorizationCode($oauth, $redirectUri)` | for a user: `start()`, keep the `AuthorizationRequest` in the session, then `exchange($started, $code, $state)` |
| `new Auth\OAuth\RefreshingToken($oauth, "user:{$id}", $store, $lock)` | after the exchange: keeps that user's token fresh; add `rotating: true` when the server rotates refresh tokens and omits a spent one |
| `new Auth\OAuth\DeviceAuthorization($oauth)` | CLIs: `start()`, show the code, `poll($code)` |
| `(new Auth\OAuth\ClientRegistration($endpoint))->register(new Auth\OAuth\ClientMetadata([...]))` | registering a client at runtime (RFC 7591); `->oauthClient($tokenEndpoint, ...)` on the result |

- **Storage:** with more than one process, use `Auth\OAuth\Psr16TokenStore` and a `RefreshLock` adapted from the framework's lock.
- **Rejected tokens:** on a 401, OAuth authenticators renew the token and retry once. Don't add that yourself.
