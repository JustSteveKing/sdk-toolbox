<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Pagination;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Query;
use JustSteveKing\SdkToolbox\Http\Response;
use Psr\Http\Message\RequestInterface;

/**
 * `?offset=0&limit=100`, then `?offset=100&limit=100`. A short page is the
 * last one.
 */
final readonly class OffsetPagination implements Pagination
{
    public function __construct(
        private int $limit = 100,
        private string $offsetParameter = 'offset',
        private ?string $limitParameter = 'limit',
    ) {
        if ($limit < 1) {
            throw new InvalidRequestException('An offset page needs a limit of at least one.');
        }
    }

    public function first(RequestInterface $request): RequestInterface
    {
        return Query::with($request, $this->parameters(0));
    }

    public function next(RequestInterface $request, Response $response, int $count): ?RequestInterface
    {
        if ($count < $this->limit) {
            return null;
        }

        $offset = (int) (Query::value($request, $this->offsetParameter) ?? 0);

        return Query::with($request, $this->parameters($offset + $count));
    }

    /**
     * @return array<string, int>
     */
    private function parameters(int $offset): array
    {
        return $this->limitParameter === null
            ? [$this->offsetParameter => $offset]
            : [$this->offsetParameter => $offset, $this->limitParameter => $this->limit];
    }
}
