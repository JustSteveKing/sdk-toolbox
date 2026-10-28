# Middleware

```php
interface Middleware
{
    public function handle(RequestInterface $request, Next $next): ResponseInterface;
}
```

A middleware can:

- change the request before passing it on;
- change the response on the way back;
- call `$next` more than once (a retry);
- not call it at all (a cache hit).

Everything is PSR-7, so requests are immutable: keep what `withHeader()` returns.

```php
final readonly class AcmeApiVersion implements Middleware
{
    public function __construct(private string $version) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        return $next->handle($request->withHeader('Acme-Version', $this->version));
    }
}
```

Pass middleware to the constructor (`middleware: [...]`), or add it to a copy with `$connector->withMiddleware(new AcmeApiVersion('2026-10-01'))`.

## The order

1. Retries
2. The throttle, if there is one
3. The deprecation watcher, if there is one
4. Your middleware, in the order given
5. Authentication, including the one retry after a rejected token
6. The HTTP cache, if there is one
7. Instrumentation and tracing, once per attempt
8. The PSR-18 client

What follows from that order:

- **Every attempt is complete.** A retry passes through the throttle, your middleware and authentication again, so a renewed token or a rate-limit wait applies to it.
- **Credentials stay late.** Nothing before authentication, your middleware and a logger included, sees them. The flip side: your middleware can't sign over the `Authorization` header.
- **The cache keys on credentials,** because it runs after authentication. Users never share entries.
- **A cache hit isn't an attempt,** so it produces no request events or spans.

## Ordering your own

Middleware that changes the body has to run before middleware that hashes or signs it. Put `Digests` before `SignRequests` and the signature covers the body through its digest ([Integrity and webhooks](integrity-and-webhooks.md)):

```php
$connector->withMiddleware(new Digests, new SignRequests($key, ['@method', '@path']));
```

## HTTPlug underneath

The client at the bottom can be any PSR-18 client, an HTTPlug `PluginClient` included. HTTPlug's plugins (cookies, history, redirects, the cache plugin) still work by wrapping the client you pass as `http:`. They run inside the toolbox's pipeline, below authentication.
