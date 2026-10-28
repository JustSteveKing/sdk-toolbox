# Security

The defaults assume an SDK will be used carelessly by somebody, eventually: a caller passes an ID straight from a form, an API's response points somewhere it shouldn't, an exception ends up in an error tracker. None of those should cost anyone a credential.

## Requests can't leave the API

- **Paths are appended to the base URI, and can't escape it.** Absolute URLs, `//host`, and `..` segments (percent-encoded ones too) are refused with `UnsafeUriException` before anything is sent ([Requests and bodies](requests-and-bodies.md#the-base-uri)).
- **URLs from the API are held to the same rule.** A pagination `next` link, an operation monitor's `Location` or a 303 target outside the base URI is refused, so a compromised or confused API can't send the client, and its credentials, elsewhere.

This is the class of hole behind Saloon's v4 SSRF fix, where an absolute URL from a request overrode the base URL.

## No credentials over plain HTTP

A connector with an authenticator won't be built against an `http://` base URI unless you pass `insecure: true`, and every OAuth endpoint has to be HTTPS. That's draft-ietf-httpapi-privacy's rule.

## Secrets are redacted everywhere

| Where | How |
|---|---|
| Exceptions | `HttpException` and `TransportException` hold a `RequestSnapshot` with sensitive headers and query parameters masked, never the PSR-7 request |
| HTTP client error messages | `name=value` masked for every sensitive query parameter, since clients often quote the full URL |
| `var_dump()`, `print_r()` | connectors, authenticators, OAuth clients, tokens and keys show `[redacted]`; constructor arguments carry `#[SensitiveParameter]` |
| Log lines and events | built from the same redacted snapshots |
| OpenTelemetry | `url.full` is redacted |
| Recorded fixtures | masked before they're written |
| Problem types | resolved without the request's query string |

The sensitive names start with:

- **headers:** `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `Api-Key` and `X-Auth-Token`;
- **query parameters:** `api_key`, `apikey`, `key`, `token`, `access_token`, `refresh_token`, `client_secret`, `password`, `signature` and `sig`.

An authenticator adds whatever header or parameter it writes to, and you can pass your own `Redactor` with more names.

One gap, on purpose: a `TransportException` keeps the HTTP client's own exception as `getPrevious()`, unmasked, because it's the useful part when debugging. Don't log it unfiltered.

## Nothing is unserialized

The HTTP cache, the token store and the rate-limit store keep plain arrays in PSR-16 caches, and ignore anything else they find under their keys. Nothing anywhere calls `unserialize()`. Saloon's v4 fixed a remote code execution that came from unserializing stored tokens.

## Fixtures stay in their directory

A recorded fixture's name is letters, digits, `-`, `_` and `/`, and resolves inside the fixtures directory. A name can't read or write a file anywhere else, which was Saloon's third v4 fix.

## Signatures and webhooks

- Every signature and HMAC comparison is constant-time (`hash_equals`, sodium).
- Webhook timestamps are checked against a tolerance, and RFC 9421 signatures against a maximum age and their expiry.
- An RFC 9421 signature's `alg` has to match its key's, so a key can't be used with an algorithm it wasn't meant for.
- OAuth's `state` is compared in constant time, and PKCE is S256 only.

## Uploads

`Part::file()` takes streams, resources or strings, never a file path, so an SDK can't be talked into reading a path that came from somebody else. Multipart names and filenames are escaped so they can't break the framing.

## Reporting a vulnerability

Please don't open a public issue. Use GitHub's [private vulnerability reporting](https://github.com/JustSteveKing/sdk-toolbox/security/advisories/new), or email steve@juststeveking.com. [SECURITY.md](../SECURITY.md) has the details.
