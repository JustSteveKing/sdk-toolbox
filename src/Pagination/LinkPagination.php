<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use Http\Discovery\Psr17FactoryDiscovery;
use JustSteveKing\SdkToolbox\Http\Links;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Follows `Link: <...>; rel="next"` (RFC 8288), as GitHub and many others
 * page. The API decides everything about the next page; the SDK just goes
 * where it's told, and the connector makes sure that's still under the
 * base URI.
 */
final readonly class LinkPagination implements Pagination
{
    private UriFactoryInterface $uris;

    public function __construct(
        private string $relation = 'next',
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
        $link = Links::find($response->psr()->getHeader('Link'), (string) $request->getUri(), $this->relation);

        return $link === null ? null : $request->withUri($this->uris->createUri($link->target));
    }
}
