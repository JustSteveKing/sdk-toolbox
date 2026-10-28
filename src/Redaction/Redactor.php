<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Redaction;

use NoDiscard;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Uri\Rfc3986\Uri;

/**
 * Keeps credentials out of exceptions, logs and dumps.
 *
 * Headers and query parameters are matched by name, case-insensitively.
 * The defaults cover the usual places a key travels; an authenticator that
 * puts one somewhere else declares it, and the connector adds it here.
 */
final readonly class Redactor
{
    public const string MASK = '[redacted]';

    public const array HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
    ];

    public const array QUERY = [
        'api_key',
        'apikey',
        'key',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'password',
        'signature',
        'sig',
    ];

    /** @var list<string> */
    private array $headers;

    /** @var list<string> */
    private array $query;

    /**
     * @param  list<string>  $headers  more header names to mask
     * @param  list<string>  $query  more query parameter names to mask
     */
    public function __construct(array $headers = [], array $query = [])
    {
        $this->headers = array_values(array_unique([...self::HEADERS, ...array_map(strtolower(...), $headers)]));
        $this->query = array_values(array_unique([...self::QUERY, ...array_map(strtolower(...), $query)]));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $query
     */
    #[NoDiscard]
    public function with(array $headers = [], array $query = []): self
    {
        return new self([...$this->headers, ...$headers], [...$this->query, ...$query]);
    }

    public function isSensitiveHeader(string $name): bool
    {
        return in_array(strtolower($name), $this->headers, true);
    }

    /**
     * A URI with sensitive query values masked and any user info removed.
     */
    public function uri(string $uri): string
    {
        $parsed = Uri::parse($uri);

        if ($parsed === null) {
            return self::MASK;
        }

        $parsed = $parsed->withUserInfo(null);
        $query = $parsed->getRawQuery();

        if ($query === null || $query === '') {
            return $parsed->toRawString();
        }

        $pairs = array_map(function (string $pair): string {
            [$name] = explode('=', $pair, 2);

            return in_array(strtolower(rawurldecode($name)), $this->query, true) ? "{$name}=".rawurlencode(self::MASK) : $pair;
        }, explode('&', $query));

        return $parsed->withQuery(implode('&', $pairs))->toRawString();
    }

    /**
     * A message's headers, with sensitive values masked.
     *
     * @return array<string, list<string>>
     */
    public function headers(MessageInterface $message): array
    {
        $headers = [];

        foreach ($message->getHeaders() as $name => $values) {
            $headers[(string) $name] = $this->isSensitiveHeader((string) $name) ? [self::MASK] : array_values($values);
        }

        return $headers;
    }

    public function request(RequestInterface $request): RequestSnapshot
    {
        return new RequestSnapshot(
            method: $request->getMethod(),
            uri: $this->uri((string) $request->getUri()),
            headers: $this->headers($request),
        );
    }

    /**
     * Masks credentials in free text, such as an HTTP client's error, which
     * often quotes the full URL: every `name=value` for a sensitive query
     * parameter, wherever it appears.
     */
    public function message(string $message): string
    {
        $names = implode('|', array_map(static fn (string $name): string => preg_quote(rawurlencode($name), '/'), $this->query));

        return preg_replace("/(?<![\\w.-])({$names})=[^&\\s\"'#)]*/i", '$1='.rawurlencode(self::MASK), $message) ?? self::MASK;
    }
}
