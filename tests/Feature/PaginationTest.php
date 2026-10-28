<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\PaginationException;
use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Pagination\CursorPagination;
use JustSteveKing\SdkToolbox\Pagination\LinkPagination;
use JustSteveKing\SdkToolbox\Pagination\OffsetPagination;
use JustSteveKing\SdkToolbox\Pagination\PagePagination;
use JustSteveKing\SdkToolbox\Pagination\StartingAfterPagination;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Acme;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Role;
use Psr\Http\Message\RequestInterface;

/**
 * @param  list<string>  $ids
 * @return array<string, mixed>
 */
function membersPage(array $ids, array $extra = []): array
{
    return ['data' => array_map(fn (string $id): array => member($id), $ids), ...$extra];
}

/**
 * @param  list<RequestInterface>  $sent
 * @return list<string>
 */
function queries(array $sent): array
{
    return array_map(fn (RequestInterface $r): string => $r->getUri()->getQuery(), $sent);
}

it('follows Link headers to the end', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b']), headers: ['Link' => '</v2/members?page=2>; rel="next"']),
        FakeResponse::json(membersPage(['c']), headers: ['Link' => '<?page=3>; rel="next", </v2/members?page=1>; rel="first"']),
        FakeResponse::json(membersPage(['d']), headers: ['Link' => '</v2/members?page=1>; rel="first"']),
    );

    $ids = array_map(fn (Member $m): string => $m->id, new Acme('t', $http)->paginate(new ListMembers, new LinkPagination)->all());

    expect($ids)->toBe(['a', 'b', 'c', 'd'])
        ->and(queries($http->sent()))->toBe(['', 'page=2', 'page=3']);
    $http->assertSent(fn (RequestInterface $r): bool => $r->getHeaderLine('Authorization') === 'Bearer t' && $r->getUri()->getQuery() === 'page=3');
});

it('refuses a next link that leaves the base URI', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a']), headers: ['Link' => '<https://evil.example/steal>; rel="next"']));

    expect(fn () => new Acme('t', $http)->paginate(new ListMembers, new LinkPagination)->all())->toThrow(UnsafeUriException::class);
    $http->assertSentCount(1);
});

it('sends a body cursor back, keeping the rest of the query', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['meta' => ['next_cursor' => 'c2']])),
        FakeResponse::json(membersPage(['b'], ['meta' => ['next_cursor' => null]])),
    );

    new Acme('t', $http)->paginate(new ListMembers(Role::Owner), new CursorPagination('meta.next_cursor'))->all();

    expect(queries($http->sent()))->toBe(['role=owner', 'role=owner&cursor=c2']);
});

it('pages like Stripe, starting after the last item', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['has_more' => true])),
        FakeResponse::json(membersPage(['c'], ['has_more' => false])),
    );

    expect(new Acme('t', $http)->paginate(new ListMembers, new StartingAfterPagination)->all())->toHaveCount(3)
        ->and(queries($http->sent()))->toBe(['', 'starting_after=b']);
});

it('pages by offset and stops on a short page', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'])),
        FakeResponse::json(membersPage(['c', 'd'])),
        FakeResponse::json(membersPage(['e'])),
    );

    new Acme('t', $http)->paginate(new ListMembers, new OffsetPagination(limit: 2))->all();

    expect(queries($http->sent()))->toBe(['offset=0&limit=2', 'offset=2&limit=2', 'offset=4&limit=2']);
});

it('pages by number and stops at the last page the body names', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['meta' => ['last_page' => 2]])),
        FakeResponse::json(membersPage(['b'], ['meta' => ['last_page' => 2]])),
    );

    new Acme('t', $http)->paginate(new ListMembers, new PagePagination(lastPageAt: 'meta.last_page'))->all();

    expect(queries($http->sent()))->toBe(['page=1', 'page=2']);
    $http->assertQueueEmpty();
});

it('fetches only the pages it needs', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['next_cursor' => 'x'])),
        FakeResponse::json(membersPage(['c', 'd'], ['next_cursor' => 'y'])),
    );

    expect(new Acme('t', $http)->paginate(new ListMembers, new CursorPagination)->take(3))->toHaveCount(3);
    $http->assertSentCount(2);
});

it('gives pages with their response', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a']), headers: ['X-Total' => '1']));

    foreach (new Acme('t', $http)->paginate(new ListMembers, new CursorPagination)->pages() as $page) {
        expect($page->number)->toBe(1)
            ->and($page->items)->toHaveCount(1)
            ->and($page->response->header('X-Total'))->toBe('1');
    }
});

it('throws instead of looping when a page points at itself', function (): void {
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::json(membersPage(['a'], ['next_cursor' => 'same']))));

    expect(fn () => new Acme('t', $http)->paginate(new ListMembers, new CursorPagination)->all())->toThrow(PaginationException::class);
});

it('stops at maxPages', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next_cursor' => '1'])),
        FakeResponse::json(membersPage(['b'], ['next_cursor' => '2'])),
    );

    expect(new Acme('t', $http)->paginate(new ListMembers, new CursorPagination, maxPages: 2)->all())->toHaveCount(2);
    $http->assertSentCount(2);
});

it('throws the status exception for a page that fails', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next_cursor' => 'gone'])),
        FakeResponse::problem(404, title: 'Cursor expired'),
    );

    expect(fn () => new Acme('t', $http)->paginate(new ListMembers, new CursorPagination)->all())->toThrow(NotFoundException::class);
});
