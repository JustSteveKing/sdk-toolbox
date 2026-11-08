<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Query;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Stripe's shape: `{"data": [...], "has_more": true}`, and the next page
 * starts after the last item's ID, `?starting_after=<id>`.
 *
 * There's no standard for this; it's a convention. Some APIs page after
 * the last ID but send no "more" flag at all, and a page shorter than the
 * limit is the last. For those, pass `hasMoreAt: null` and the `limit`:
 * paging stops on an empty page or a short one, so nothing is asked for
 * past the end. Without a limit it can only stop on an empty page, which
 * costs one more request.
 *
 * A `limit` is sent on the first request as `limitParameter`, and every
 * next page keeps it, since the next request is the last one with only
 * `starting_after` changed. Pass `limitParameter: null` when the limit is
 * the API's own page size and isn't sent.
 */
final readonly class StartingAfterPagination implements Pagination
{
    /**
     * @param  string|null  $hasMoreAt  dotted path to the "more" flag, or null when the API sends none
     * @param  int|null  $limit  the page size, sent on the first request, and how a short page is told
     */
    public function __construct(
        private string $itemsAt = 'data',
        private ?string $hasMoreAt = 'has_more',
        private string $idKey = 'id',
        private string $parameter = 'starting_after',
        private ?int $limit = null,
        private ?string $limitParameter = 'limit',
    ) {
        if ($limit !== null && $limit < 1) {
            throw new InvalidRequestException('A starting_after page needs a limit of at least one.');
        }
    }

    public function first(RequestInterface $request): RequestInterface
    {
        return $this->limit === null || $this->limitParameter === null
            ? $request
            : Query::with($request, [$this->limitParameter => $this->limit]);
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        $items = $response->get($this->itemsAt);

        if (! is_array($items) || $items === []) {
            return null;
        }

        if ($this->hasMoreAt === null ? $this->limit !== null && count($items) < $this->limit : $response->get($this->hasMoreAt) !== true) {
            return null;
        }

        $last = $items[array_key_last($items)];
        $id = is_array($last) ? ($last[$this->idKey] ?? null) : null;

        return is_string($id) || is_int($id) ? Query::with($request, [$this->parameter => $id]) : null;
    }
}
