<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Http\Query;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * `?page=1`, `?page=2`... Stops on an empty page, on a short page when the
 * page size is known, or at the last page when the body says which that is.
 */
final readonly class PagePagination implements Pagination
{
    /**
     * @param  string|null  $lastPageAt  dotted path to the last page number in the body, such as `meta.last_page`
     */
    public function __construct(
        private string $parameter = 'page',
        private int $firstPage = 1,
        private ?int $perPage = null,
        private string $perPageParameter = 'per_page',
        private ?string $lastPageAt = null,
    ) {}

    public function first(RequestInterface $request): RequestInterface
    {
        return Query::with($request, [
            $this->parameter => $this->firstPage,
            ...($this->perPage === null ? [] : [$this->perPageParameter => $this->perPage]),
        ]);
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        $page = (int) (Query::value($request, $this->parameter) ?? $this->firstPage);
        $last = $this->lastPageAt === null ? null : $response->get($this->lastPageAt);

        if ($count === 0 || ($this->perPage !== null && $count < $this->perPage) || (is_int($last) && $page >= $last)) {
            return null;
        }

        return Query::with($request, [$this->parameter => $page + 1]);
    }
}
