<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use Closure;
use Generator;
use IteratorAggregate;
use JustSteveKing\SdkToolbox\Errors\PaginationException;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;
use Psr\Http\Message\RequestInterface;

/**
 * Every item across every page, fetched lazily.
 *
 * Only the page being read is in memory: iterating a million items holds
 * one page at a time, unless you collect them with all(). Each page is a
 * full request through the connector, so retries, the throttle and your
 * middleware apply to every one.
 *
 *     foreach ($acme->paginate(new ListProjects, new CursorPagination('meta.next_cursor')) as $project) {
 *         // ...
 *     }
 *
 * A request repeating the previous one (the API linked a page to itself)
 * throws instead of looping, and `maxPages` caps a longer cycle.
 *
 * @template TItem
 *
 * @implements IteratorAggregate<int, TItem>
 */
final readonly class Paginator implements IteratorAggregate
{
    /**
     * @param  Request<iterable<TItem>>  $request
     * @param  Closure(RequestInterface): Response  $send
     */
    public function __construct(
        private Request $request,
        private RequestInterface $first,
        private Pagination $pagination,
        private Closure $send,
        private ?int $maxPages = null,
    ) {}

    /**
     * @return Generator<int, TItem>
     */
    public function getIterator(): Generator
    {
        $index = 0;

        foreach ($this->pages() as $page) {
            foreach ($page->items as $item) {
                yield $index++ => $item;
            }
        }
    }

    /**
     * @return Generator<int, Page<TItem>>
     */
    public function pages(): Generator
    {
        $psr = $this->pagination->first($this->first);
        $previous = null;

        for ($number = 1; $this->maxPages === null || $number <= $this->maxPages; $number++) {
            $uri = (string) $psr->getUri();

            if ($uri === $previous) {
                throw new PaginationException("Page {$number} is the same request as the page before it: {$psr->getMethod()} {$psr->getUri()->getPath()}.");
            }

            $response = ($this->send)($psr);
            $items = [];

            foreach ($this->request->result($response) as $item) {
                $items[] = $item;
            }

            yield $number => new Page($number, $items, $response);

            $next = $this->pagination->next($psr, $response, count($items));

            if ($next === null) {
                return;
            }

            $previous = $uri;
            $psr = $next;
        }
    }

    /**
     * The first `$count` items, fetching only the pages needed.
     *
     * @return list<TItem>
     */
    public function take(int $count): array
    {
        $items = [];

        if ($count < 1) {
            return $items;
        }

        foreach ($this as $item) {
            $items[] = $item;

            if (count($items) >= $count) {
                break;
            }
        }

        return $items;
    }

    /**
     * Every item in memory at once. Fine for a few pages; iterate instead
     * for more.
     *
     * @return list<TItem>
     */
    public function all(): array
    {
        return iterator_to_array($this, false);
    }
}
