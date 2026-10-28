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
 */
final readonly class NextUrlPagination implements Pagination
{
    private UriFactoryInterface $uris;

    /**
     * @param  string  $nextAt  dotted path to the next page's URL in the body
     */
    public function __construct(
        private string $nextAt = 'next',
        ?UriFactoryInterface $uris = null,
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

        return $request->withUri($this->uris->createUri($resolved->toString()));
    }
}
