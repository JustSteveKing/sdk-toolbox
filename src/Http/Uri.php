<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * An RFC 3986 URI reference: parsed, validated, and resolved against a base.
 *
 * - **Parsing** uses Appendix B's regular expression.
 * - **Resolution** follows section 5.2's algorithm, dot-segment removal
 *   included, and is tested against section 5.4's examples.
 * - **Normalisation:** the scheme and host are lowercased (6.2.2.1).
 *   Nothing else is normalised.
 *
 * It exists so the toolbox runs on PHP 8.3, where 8.5's `Uri\Rfc3986\Uri`
 * isn't available, and keeps that class's method names.
 *
 * @internal
 */
final readonly class Uri
{
    private const string UNRESERVED_OR_RESERVED = "A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=";

    private function __construct(
        private ?string $scheme,
        private ?string $userInfo,
        private ?string $host,
        private ?int $port,
        private string $path,
        private ?string $query,
        private ?string $fragment,
    ) {}

    /**
     * Parses a URI reference, resolving it against `$base` when one is
     * given. Null when it isn't valid RFC 3986 (a space, a stray `%`).
     */
    public static function parse(string $uri, ?self $base = null): ?self
    {
        $reference = self::reference($uri);

        if ($reference === null) {
            return null;
        }

        return $base === null ? $reference : $base->resolveReference($reference);
    }

    public function getScheme(): ?string
    {
        return $this->scheme;
    }

    public function getUserInfo(): ?string
    {
        return $this->userInfo;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function getRawQuery(): ?string
    {
        return $this->query;
    }

    public function getFragment(): ?string
    {
        return $this->fragment;
    }

    public function withPath(string $path): self
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $path, $this->query, $this->fragment);
    }

    public function withQuery(?string $query): self
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $query, $this->fragment);
    }

    public function withFragment(?string $fragment): self
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $this->query, $fragment);
    }

    public function withUserInfo(?string $userInfo): self
    {
        return new self($this->scheme, $userInfo, $this->host, $this->port, $this->path, $this->query, $this->fragment);
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /**
     * Recomposition (section 5.3).
     */
    public function toString(): string
    {
        $uri = $this->scheme === null ? '' : "{$this->scheme}:";

        if ($this->host !== null) {
            $uri .= '//'.($this->userInfo === null ? '' : "{$this->userInfo}@").$this->host.($this->port === null ? '' : ":{$this->port}");
        }

        $uri .= $this->path;
        $uri .= $this->query === null ? '' : "?{$this->query}";

        return $uri.($this->fragment === null ? '' : "#{$this->fragment}");
    }

    public function toRawString(): string
    {
        return $this->toString();
    }

    /**
     * Section 5.2.2, with strict parsing: a reference's own scheme always
     * wins.
     */
    private function resolveReference(self $reference): self
    {
        if ($reference->scheme !== null) {
            return $reference->withPath(self::removeDotSegments($reference->path));
        }

        if ($reference->host !== null) {
            return new self($this->scheme, $reference->userInfo, $reference->host, $reference->port, self::removeDotSegments($reference->path), $reference->query, $reference->fragment);
        }

        if ($reference->path === '') {
            return new self($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $reference->query ?? $this->query, $reference->fragment);
        }

        $path = str_starts_with($reference->path, '/')
            ? self::removeDotSegments($reference->path)
            : self::removeDotSegments($this->merge($reference->path));

        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $path, $reference->query, $reference->fragment);
    }

    /**
     * Section 5.2.3.
     */
    private function merge(string $path): string
    {
        if ($this->host !== null && $this->path === '') {
            return "/{$path}";
        }

        $slash = strrpos($this->path, '/');

        return $slash === false ? $path : substr($this->path, 0, $slash + 1).$path;
    }

    /**
     * Section 5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = '';

        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = substr($path, 2);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../') || $path === '/..') {
                $path = $path === '/..' ? '/' : substr($path, 3);
                $slash = strrpos($output, '/');
                $output = $slash === false ? '' : substr($output, 0, $slash);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                $next = strpos($path, '/', 1);
                $segment = $next === false ? $path : substr($path, 0, $next);
                $output .= $segment;
                $path = $next === false ? '' : substr($path, $next);
            }
        }

        return $output;
    }

    /**
     * Appendix B's regular expression, then each part checked against the
     * section 3 grammar it has to match.
     */
    private static function reference(string $uri): ?self
    {
        $allowed = self::UNRESERVED_OR_RESERVED;

        if (preg_match("/^(?:[{$allowed}]|%[0-9A-Fa-f]{2})*$/", $uri) !== 1) {
            return null;
        }

        preg_match('/^(([^:\/?#]+):)?(\/\/([^\/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$/', $uri, $m);

        $scheme = ($m[1] ?? '') === '' ? null : $m[2];

        if ($scheme !== null && preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*$/', $scheme) !== 1) {
            return null;
        }

        $userInfo = null;
        $host = null;
        $port = null;

        if (($m[3] ?? '') !== '') {
            $authority = $m[4];
            $at = strrpos($authority, '@');

            if ($at !== false) {
                $userInfo = substr($authority, 0, $at);
                $authority = substr($authority, $at + 1);
            }

            if (preg_match('/^(\[[^\]]+\]|[^:\[\]]*)(?::(\d*))?$/', $authority, $hostPort) !== 1) {
                return null;
            }

            $host = strtolower($hostPort[1]);
            $port = isset($hostPort[2]) && $hostPort[2] !== '' ? (int) $hostPort[2] : null;
        }

        return new self(
            scheme: $scheme === null ? null : strtolower($scheme),
            userInfo: $userInfo,
            host: $host,
            port: $port,
            path: $m[5] ?? '',
            query: ($m[6] ?? '') === '' ? null : ($m[7] ?? ''),
            fragment: ($m[8] ?? '') === '' ? null : ($m[9] ?? ''),
        );
    }
}
