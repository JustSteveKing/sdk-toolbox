<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;

/**
 * The one address an SDK talks to, and the only one.
 *
 * Request paths are appended to the base path, so `/applications` under
 * `https://api.example.com/v2` is `/v2/applications`. Plain RFC 3986
 * resolution would drop the `/v2`, and would let `//elsewhere.com` or an
 * absolute URL send the request, and its credentials, to another host. A
 * path that would leave the base after normalisation is refused.
 */
final readonly class BaseUri
{
    private Uri $uri;

    public function __construct(string $uri)
    {
        $parsed = Uri::parse($uri);

        if ($parsed === null || $parsed->getScheme() === null || $parsed->getHost() === null || $parsed->getHost() === '') {
            throw new UnsafeUriException("The base URI must be absolute, with a scheme and a host: [{$uri}].");
        }

        if ($parsed->getQuery() !== null || $parsed->getFragment() !== null) {
            throw new UnsafeUriException("The base URI can't carry a query or fragment: [{$uri}].");
        }

        if ($parsed->getUserInfo() !== null) {
            throw new UnsafeUriException('The base URI can\'t carry credentials; use an authenticator.');
        }

        $path = $parsed->getPath();
        $this->uri = $parsed->withPath(str_ends_with($path, '/') ? $path : "{$path}/");
    }

    /**
     * The full URI for a request path, which is always under this base.
     *
     * @throws UnsafeUriException when the path would leave the base
     */
    public function resolve(string $path): Uri
    {
        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new UnsafeUriException("A request path can't carry a query or fragment; use the request's query(): [{$path}].");
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1 || str_starts_with($path, '//')) {
            throw new UnsafeUriException("A request path must be relative to the base URI: [{$path}].");
        }

        // RFC 3986 only removes literal dot segments; a server that decodes
        // `%2e%2e` first would still walk up, so encoded ones are refused too.
        if (in_array('..', array_map(rawurldecode(...), explode('/', $path)), true)) {
            throw new UnsafeUriException("A request path can't contain `..` segments: [{$path}].");
        }

        $resolved = Uri::parse(ltrim($path, '/'), $this->uri);

        if ($resolved === null) {
            throw new UnsafeUriException("Not a valid request path: [{$path}].");
        }

        if (! $this->contains($resolved)) {
            throw new UnsafeUriException("The request path leaves the base URI: [{$path}].");
        }

        return $resolved;
    }

    /**
     * Whether an absolute URI, such as a `next` link, is under this base.
     */
    public function allows(string $uri): bool
    {
        $parsed = Uri::parse($uri);

        return $parsed !== null && $this->contains($parsed);
    }

    public function scheme(): string
    {
        return (string) $this->uri->getScheme();
    }

    public function toString(): string
    {
        return $this->uri->toString();
    }

    private function contains(Uri $uri): bool
    {
        return $uri->getScheme() === $this->uri->getScheme()
            && $uri->getHost() === $this->uri->getHost()
            && $uri->getPort() === $this->uri->getPort()
            && $uri->getUserInfo() === null
            && str_starts_with($uri->getPath(), $this->uri->getPath());
    }
}
