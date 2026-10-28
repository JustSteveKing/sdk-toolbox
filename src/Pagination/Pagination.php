<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * How an API pages: what the first request looks like, and what the next
 * one is after a page has come back.
 *
 * Strategies work on the PSR-7 request, so a Request class doesn't need to
 * know it's paginated: the same GetProjects returns one page from send()
 * and every page from paginate().
 */
interface Pagination
{
    public function first(RequestInterface $request): RequestInterface;

    /**
     * @param  int  $count  how many items the page just read held
     * @return RequestInterface|null the next page's request, or null after the last page
     */
    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface;
}
