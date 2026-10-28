# Authentication

An authenticator adds credentials to each request. It runs last, just before the HTTP client, so:

- a retried request is authenticated afresh;
- nothing earlier in the pipeline, a logger included, sees the credentials.

## Static credentials

| Authenticator | Sends |
|---|---|
| `new BearerToken($token)` | `Authorization: Bearer <token>` (RFC 6750 §2.1) |
| `new BasicAuth($user, $password)` | `Authorization: Basic ...` (RFC 7617) |
| `new HeaderKey('X-Api-Key', $key)` | the key in a header of the API's choosing |
| `new QueryKey('api_key', $key)` | the key in the query string, for APIs that give no other choice |

The header or parameter a `HeaderKey` or `QueryKey` writes to is masked in exceptions and logs, whatever it's called. `QueryKey` also replaces any value a request's own query had for that parameter, so a caller can't swap in a different key.

### No credentials over plain HTTP

A connector with an authenticator and an `http://` base URI refuses to be built. Credentials over an unencrypted channel are what draft-ietf-httpapi-privacy says a client MUST NOT send. For a local server, say so:

```php
parent::__construct(baseUri: 'http://localhost:8080', auth: $auth, insecure: true);
```

## OAuth 2.0

Describe the client registration once:

```php
$oauth = new OAuthClient(
    tokenEndpoint: 'https://auth.acme.test/oauth/token',
    clientId: $id,
    clientSecret: $secret,   // null for a public client: a CLI, a mobile app
    authorizationEndpoint: 'https://auth.acme.test/oauth/authorize',
    deviceAuthorizationEndpoint: 'https://auth.acme.test/oauth/device',
);
```

- **TLS:** every endpoint has to be HTTPS, unless you pass `insecure: true`.
- **Client authentication** uses HTTP Basic. Following RFC 6749 §2.3.1, the ID and secret are form-encoded before Basic encoding, which is the step most clients miss: a secret with a `+` in it fails without it. Pass `authentication: ClientAuthentication::Post` for servers that want them in the body instead.
- **The token endpoint** is called directly over PSR-18, not through your connector. It's usually another host with different rules.
- **Scopes** are listed with spaces, as RFC 6749 §3.3 says. Some servers (Todoist among them) want commas instead: pass `scopeSeparator: ','`, and every flow sends them that way and reads the granted scopes back the same way.

### Server to server: client credentials

```php
parent::__construct(
    baseUri: 'https://api.acme.test',
    auth: new ClientCredentials($oauth, scopes: ['projects:read']),
);
```

It fetches a token the first time one's needed and reuses it until 30 seconds before it expires. It needs a confidential client (one with a secret), as RFC 6749 §4.4 says.

### For a user: authorisation code with PKCE

Start the flow, and keep what it gives you in the session:

```php
$flow = new AuthorizationCode($oauth, redirectUri: 'https://app.test/oauth/callback');

$started = $flow->start(['projects:read', 'offline']);
$session->put('acme_oauth', $started);

return redirect($started->url);
```

When the user comes back:

```php
$started = $session->pull('acme_oauth');
$token = $flow->exchange($started, $request->query('code'), $request->query('state'));

$store->put("user:{$user->id}", $token);
```

- **PKCE** is used for every client, confidential ones included, as RFC 9700 recommends. It's always S256, never `plain`.
- **The returned `state`** is checked in constant time. A mismatch throws `OAuthException` with the error `invalid_state` before anything is sent.

From then on, `RefreshingToken` keeps that user's token fresh:

```php
auth: new RefreshingToken($oauth, "user:{$user->id}", $store, $lock),
```

It refreshes shortly before expiry, and replaces the refresh token whenever the server issues a new one, as RFC 6749 §6 requires. With no refresh token left it throws `OAuthException` (`no_refresh_token`): the user has to authorise again.

When a reply has no refresh token, the old one is kept, as RFC 6749 §6 allows. A server that rotates refresh tokens (RFC 9700 §4.14.2) can instead omit it because the old one is already spent: Todoist does this when a refresh is retried within its grace window, and presenting the spent token later reads as a replay that revokes the user's whole grant. Pass `rotating: true` for such a server, and a reply without a refresh token leaves none stored.

### For a CLI: the device flow

```php
$flow = new DeviceAuthorization($oauth);

$code = $flow->start(['projects:read']);
echo "Go to {$code->verificationUri} and enter {$code->userCode}\n";

$token = $flow->poll($code);
```

Polling follows RFC 8628 §3.5:

- it waits `interval` seconds (5 if the server gives none);
- it adds 5 more on every `slow_down`;
- it doubles the wait after a connection failure;
- it stops on a denial or when the code expires.

## When the API rejects a token

If an OAuth authenticator gets a 401, it renews the credentials and sends the request once more (RFC 6750 §3.1).

- **What counts:** an `invalid_token` challenge, or a bare 401 with no `WWW-Authenticate`, which plenty of APIs send.
- **What doesn't:** a challenge naming another error, such as `invalid_request`, or a 403 `insufficient_scope`. New credentials won't fix those.
- **Your own authenticator** gets the same behaviour by implementing `Refreshable`.

## Storing tokens

| Store | For |
|---|---|
| `InMemoryTokenStore` (the default) | one process: a CLI command, a worker |
| `new Psr16TokenStore($cache)` | a web application, or anything with more than one process |

`Psr16TokenStore` keeps tokens as plain arrays and ignores anything else it finds under its key. It never calls `unserialize()`, the hole behind Saloon's v4 remote code execution fix. Tokens are secrets, so use a cache only your application can read.

## Refreshing once, across processes

Ten workers that find the same expired token shouldn't refresh it ten times. With refresh-token rotation, all but the first would be using a refresh token the server has already retired. Every refresh runs inside a `RefreshLock`, and the first thing it does inside the lock is read the store again. If another process refreshed while this one waited, it uses that token and fetches nothing.

The default `ProcessLock` only covers one process. With a shared store, adapt your framework's lock:

```php
final readonly class SymfonyRefreshLock implements RefreshLock
{
    public function __construct(private LockFactory $locks) {}

    public function synchronized(string $key, Closure $callback): mixed
    {
        $lock = $this->locks->createLock("token-refresh.{$key}", 30);
        $lock->acquire(blocking: true);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
```

A token the API has rejected is never handed out again, even if its expiry says it's still good.

## Not supported

- **DPoP (RFC 9449):** not supported yet. A token type other than Bearer is refused, as RFC 6749 §7.1 says a client must.
- **The implicit grant:** not offered. RFC 9700 says not to use it.
