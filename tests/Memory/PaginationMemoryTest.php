<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Pagination\CursorPagination;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Acme;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Saloon's cursor paginator was reported growing linearly over 72k records
 * (saloonphp/saloon discussion 531). This walks 100,000 items and checks
 * memory stays flat between the first few pages and the last.
 */
it('holds one page at a time, however many pages there are', function (): void {
    $pages = 2_000;
    $perPage = 50;

    // A client that makes each page on demand and keeps nothing, unlike
    // FakeClient, which records every request.
    $http = new class($pages, $perPage) implements ClientInterface
    {
        public function __construct(private int $pages, private int $perPage) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            parse_str($request->getUri()->getQuery(), $query);
            $page = is_numeric($query['cursor'] ?? null) ? (int) $query['cursor'] : 1;

            return FakeResponse::json([
                'data' => array_map(fn (int $i): array => member("m_{$page}_{$i}"), range(1, $this->perPage)),
                'next_cursor' => $page < $this->pages ? (string) ($page + 1) : null,
            ]);
        }
    };

    $count = 0;
    $baseline = 0;

    foreach ((new Acme('t', $http))->withRetry(RetryPolicy::none())->paginate(new ListMembers, new CursorPagination) as $member) {
        $count++;

        if ($count === 10 * $perPage) {
            gc_collect_cycles();
            $baseline = memory_get_usage();
        }
    }

    gc_collect_cycles();

    expect($count)->toBe($pages * $perPage)
        ->and(memory_get_usage() - $baseline)->toBeLessThan(256 * 1024);
});
