<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Changes query parameters on a PSR-7 request, leaving the rest of the
 * query exactly as it was.
 *
 * @internal
 */
final class Query
{
    /**
     * @param  array<string, mixed>  $parameters  null removes the parameter
     */
    public static function with(RequestInterface $request, array $parameters): RequestInterface
    {
        $uri = $request->getUri();
        $names = array_keys($parameters);

        $kept = array_filter(
            $uri->getQuery() === '' ? [] : explode('&', $uri->getQuery()),
            static fn (string $pair): bool => ! in_array(rawurldecode(explode('=', $pair, 2)[0]), $names, true),
        );

        $added = QueryString::build($parameters);

        return $request->withUri($uri->withQuery(implode('&', array_filter([...$kept, $added], static fn (string $part): bool => $part !== ''))));
    }

    public static function value(RequestInterface $request, string $name): ?string
    {
        foreach (explode('&', $request->getUri()->getQuery()) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (rawurldecode($key) === $name) {
                return rawurldecode($value);
            }
        }

        return null;
    }
}
