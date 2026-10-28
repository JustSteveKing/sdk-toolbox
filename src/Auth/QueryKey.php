<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use JustSteveKing\SdkToolbox\Http\QueryString;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * An API key in the query string, for APIs that give no other choice.
 *
 * Prefer a header where the API allows one: URLs end up in access logs and
 * proxies (RFC 6750 section 5.3 makes the same point about tokens). The
 * parameter is masked in exceptions and logs.
 */
final readonly class QueryKey implements Authenticator
{
    public function __construct(
        private string $parameter,
        #[SensitiveParameter]
        private string $key,
    ) {}

    public function authenticate(RequestInterface $request): RequestInterface
    {
        $uri = $request->getUri();
        $key = QueryString::build([$this->parameter => $this->key]);

        // Drop any value for the parameter already there, so a request's own
        // query can't stand in for the key.
        $pairs = array_filter(
            $uri->getQuery() === '' ? [] : explode('&', $uri->getQuery()),
            fn (string $pair): bool => rawurldecode(explode('=', $pair, 2)[0]) !== $this->parameter,
        );

        return $request->withUri($uri->withQuery(implode('&', [...$pairs, $key])));
    }

    public function sensitive(): array
    {
        return ['headers' => [], 'query' => [$this->parameter]];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['parameter' => $this->parameter, 'key' => '[redacted]'];
    }
}
