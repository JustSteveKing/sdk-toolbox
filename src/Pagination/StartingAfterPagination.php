<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Http\Query;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Stripe's shape: `{"data": [...], "has_more": true}`, and the next page
 * starts after the last item's ID, `?starting_after=<id>`.
 */
final readonly class StartingAfterPagination implements Pagination
{
    public function __construct(
        private string $itemsAt = 'data',
        private string $hasMoreAt = 'has_more',
        private string $idKey = 'id',
        private string $parameter = 'starting_after',
    ) {}

    public function first(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        $items = $response->get($this->itemsAt);

        if ($response->get($this->hasMoreAt) !== true || ! is_array($items) || $items === []) {
            return null;
        }

        $last = $items[array_key_last($items)];
        $id = is_array($last) ? ($last[$this->idKey] ?? null) : null;

        return is_string($id) || is_int($id) ? Query::with($request, [$this->parameter => $id]) : null;
    }
}
