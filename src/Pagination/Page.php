<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Http\Response;

/**
 * One page: its items, its number from 1, and the response it came in.
 *
 * @template TItem
 */
final readonly class Page
{
    /**
     * @param  list<TItem>  $items
     */
    public function __construct(
        public int $number,
        public array $items,
        public Response $response,
    ) {}
}
