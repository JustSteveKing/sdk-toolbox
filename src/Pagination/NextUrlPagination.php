<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use Http\Discovery\Psr17FactoryDiscovery;
use JustSteveKing\SdkToolbox\Errors\PaginationException;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Http\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * The next page's URL in the body: `{"results": [...], "next": "https://..."}`,
 * as Django REST Framework and Packagist's search page. Stops when it's
 * missing, null or empty.
 *
 * There's no standard for this; it's the body-borne version of a `Link`
 * header (RFC 8288), and it's treated the same way. A relative URL is
 * resolved against the request (RFC 3986 section 5.2), and the connector
 * refuses one outside the base URI before it's sent.
 *
 * Some APIs' next URLs drop options the first request sent, such as a
 * format or a cache setting, so the second page quietly
 * answers a different question. `carry` names the query parameters to put
 * back: each one the request had and the next URL lacks is added, raw, as
 * the request sent it. A value the API wrote into the next URL is never
 * overridden. Credentials aren't carried: the request here is the one before
 * authentication, and an authenticator such as `QueryKey` sets its own
 * parameter on every page regardless.
 */
final readonly class NextUrlPagination implements Pagination
{
    private UriFactoryInterface $uris;

    /**
     * @param  string  $nextAt  dotted path to the next page's URL in the body
     * @param  list<string>  $carry  query parameters to carry from each request to the next URL when it lacks them
     */
    public function __construct(
        private string $nextAt = 'next',
        ?UriFactoryInterface $uris = null,
        private array $carry = [],
    ) {
        $this->uris = $uris ?? Psr17FactoryDiscovery::findUriFactory();
    }

    public function first(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        $next = $response->get($this->nextAt);

        if ($next === null || $next === '') {
            return null;
        }

        $resolved = is_string($next) ? Uri::parse($next, Uri::parse((string) $request->getUri())) : null;

        if ($resolved === null) {
            throw new PaginationException("The next page at [{$this->nextAt}] isn't a URL.");
        }

        $uri = $this->uris->createUri($resolved->toString());

        return $request->withUri($uri->withQuery($this->carried($request->getUri()->getQuery(), $uri->getQuery())));
    }

    /**
     * The next URL's query, with each carried parameter the request had and
     * it lacks appended as the request sent it.
     */
    private function carried(string $from, string $to): string
    {
        if ($this->carry === [] || $from === '') {
            return $to;
        }

        $present = array_map(self::name(...), $to === '' ? [] : explode('&', $to));
        $pairs = $to === '' ? [] : [$to];

        foreach (explode('&', $from) as $pair) {
            $name = self::name($pair);

            if (in_array($name, $this->carry, true) && ! in_array($name, $present, true)) {
                $pairs[] = $pair;
            }
        }

        return implode('&', $pairs);
    }

    private static function name(string $pair): string
    {
        return rawurldecode(explode('=', $pair, 2)[0]);
    }
}
