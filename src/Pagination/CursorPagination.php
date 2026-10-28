<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Http\Query;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * An opaque cursor in the body, sent back as a query parameter:
 * `{"data": [...], "meta": {"next_cursor": "abc"}}` then `?cursor=abc`.
 * Stops when the cursor is missing, null or empty.
 */
final readonly class CursorPagination implements Pagination
{
    /**
     * @param  string  $cursorAt  dotted path to the next cursor in the body
     * @param  string  $parameter  the query parameter that carries it
     */
    public function __construct(
        private string $cursorAt = 'next_cursor',
        private string $parameter = 'cursor',
    ) {}

    public function first(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        $cursor = $response->get($this->cursorAt);

        if (! is_string($cursor) && ! is_int($cursor) || $cursor === '') {
            return null;
        }

        return Query::with($request, [$this->parameter => $cursor]);
    }
}
