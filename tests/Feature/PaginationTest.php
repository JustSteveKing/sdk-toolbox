<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\PaginationException;
use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Pagination\CursorPagination;
use JustSteveKing\SdkToolbox\Pagination\LinkPagination;
use JustSteveKing\SdkToolbox\Pagination\NextUrlPagination;
use JustSteveKing\SdkToolbox\Pagination\OffsetPagination;
use JustSteveKing\SdkToolbox\Pagination\PagePagination;
use JustSteveKing\SdkToolbox\Pagination\StartingAfterPagination;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;
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

    $ids = array_map(fn (Member $m): string => $m->id, (new Acme('t', $http))->paginate(new ListMembers, new LinkPagination)->all());

    expect($ids)->toBe(['a', 'b', 'c', 'd'])
        ->and(queries($http->sent()))->toBe(['', 'page=2', 'page=3']);
    $http->assertSent(fn (RequestInterface $r): bool => $r->getHeaderLine('Authorization') === 'Bearer t' && $r->getUri()->getQuery() === 'page=3');
});

it('refuses a next link that leaves the base URI', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a']), headers: ['Link' => '<https://evil.example/steal>; rel="next"']));

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers, new LinkPagination)->all())->toThrow(UnsafeUriException::class);
    $http->assertSentCount(1);
});

it('follows a next URL in the body, absolute or relative', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next' => 'https://api.acme.test/v2/members?page=2'])),
        FakeResponse::json(membersPage(['b'], ['next' => '?page=3'])),
        FakeResponse::json(membersPage(['c'], ['next' => null])),
    );

    $ids = array_map(fn (Member $m): string => $m->id, (new Acme('t', $http))->paginate(new ListMembers, new NextUrlPagination)->all());

    expect($ids)->toBe(['a', 'b', 'c'])
        ->and(queries($http->sent()))->toBe(['', 'page=2', 'page=3']);
});

it('reads the next URL from a nested path', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['meta' => ['pagination' => ['next' => '/v2/members?page=2']]])),
        FakeResponse::json(membersPage(['b'], ['meta' => ['pagination' => ['next' => '']]])),
    );

    (new Acme('t', $http))->paginate(new ListMembers, new NextUrlPagination('meta.pagination.next'))->all();

    expect(queries($http->sent()))->toBe(['', 'page=2']);
});

it('refuses a next URL in the body that leaves the base URI', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a'], ['next' => 'https://evil.example/steal'])));

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers, new NextUrlPagination)->all())->toThrow(UnsafeUriException::class);
    $http->assertSentCount(1);
});

it('refuses a next value that is not a URL', function (mixed $next): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a'], ['next' => $next])));

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers, new NextUrlPagination)->all())->toThrow(PaginationException::class);
})->with(['a number' => 2, 'a space' => 'page 2', 'an object' => [['url' => 'x']]]);

it('carries query parameters the next URL drops', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next' => '/v2/members?page=2'])),
        FakeResponse::json(membersPage(['b'], ['next' => '/v2/members?page=3'])),
        FakeResponse::json(membersPage(['c'], ['next' => null])),
    );

    (new Acme('t', $http))->paginate(new ListMembers(Role::Owner), new NextUrlPagination(carry: ['role']))->all();

    expect(queries($http->sent()))->toBe(['role=owner', 'page=2&role=owner', 'page=3&role=owner']);
});

it('never overrides a carried parameter the next URL sets itself', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next' => '/v2/members?role=admin&page=2'])),
        FakeResponse::json(membersPage(['b'], ['next' => null])),
    );

    (new Acme('t', $http))->paginate(new ListMembers(Role::Owner), new NextUrlPagination(carry: ['role']))->all();

    expect(queries($http->sent()))->toBe(['role=owner', 'role=admin&page=2']);
});

it('carries nothing the first request did not send, and only what it is told to', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next' => '/v2/members?page=2'])),
        FakeResponse::json(membersPage(['b'], ['next' => null])),
        FakeResponse::json(membersPage(['c'], ['next' => '/v2/members?page=2'])),
        FakeResponse::json(membersPage(['d'], ['next' => null])),
    );

    (new Acme('t', $http))->paginate(new ListMembers, new NextUrlPagination(carry: ['role']))->all();
    (new Acme('t', $http))->paginate(new ListMembers(Role::Owner), new NextUrlPagination)->all();

    expect(queries($http->sent()))->toBe(['', 'page=2', 'role=owner', 'page=2']);
});

it('still refuses a next URL that leaves the base URI when carrying', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a'], ['next' => 'https://evil.example/steal'])));

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers(Role::Owner), new NextUrlPagination(carry: ['role']))->all())
        ->toThrow(UnsafeUriException::class);
    $http->assertSentCount(1);
});

it('takes a query key from the authenticator on every page, never from carrying', function (): void {
    $http = new FakeClient(
        FakeResponse::json(['data' => [1], 'next' => '/v1/search?page=2&api_key=from-the-body']),
        FakeResponse::json(['data' => [2], 'next' => null]),
    );
    $connector = new Connector('https://api.example.com/v1', auth: new QueryKey('api_key', 'secret'), http: $http);
    $search = new readonly class implements HasQuery, Request
    {
        public function method(): Method
        {
            return Method::Get;
        }

        public function path(): string
        {
            return '/search';
        }

        public function query(): array
        {
            return ['q' => 'coffee'];
        }

        /**
         * @return list<mixed>
         */
        public function result(Response $response): array
        {
            /** @var list<mixed> */
            return $response->get('data');
        }
    };

    $connector->paginate($search, new NextUrlPagination(carry: ['q', 'api_key']))->all();

    expect(queries($http->sent()))->toBe(['q=coffee&api_key=secret', 'page=2&q=coffee&api_key=secret']);
});

it('sends a body cursor back, keeping the rest of the query', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['meta' => ['next_cursor' => 'c2']])),
        FakeResponse::json(membersPage(['b'], ['meta' => ['next_cursor' => null]])),
    );

    (new Acme('t', $http))->paginate(new ListMembers(Role::Owner), new CursorPagination('meta.next_cursor'))->all();

    expect(queries($http->sent()))->toBe(['role=owner', 'role=owner&cursor=c2']);
});

it('pages like Stripe, starting after the last item', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['has_more' => true])),
        FakeResponse::json(membersPage(['c'], ['has_more' => false])),
    );

    expect((new Acme('t', $http))->paginate(new ListMembers, new StartingAfterPagination)->all())->toHaveCount(3)
        ->and(queries($http->sent()))->toBe(['', 'starting_after=b']);
});

it('pages by offset and stops on a short page', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'])),
        FakeResponse::json(membersPage(['c', 'd'])),
        FakeResponse::json(membersPage(['e'])),
    );

    (new Acme('t', $http))->paginate(new ListMembers, new OffsetPagination(limit: 2))->all();

    expect(queries($http->sent()))->toBe(['offset=0&limit=2', 'offset=2&limit=2', 'offset=4&limit=2']);
});

it('pages by number and stops at the last page the body names', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['meta' => ['last_page' => 2]])),
        FakeResponse::json(membersPage(['b'], ['meta' => ['last_page' => 2]])),
    );

    (new Acme('t', $http))->paginate(new ListMembers, new PagePagination(lastPageAt: 'meta.last_page'))->all();

    expect(queries($http->sent()))->toBe(['page=1', 'page=2']);
    $http->assertQueueEmpty();
});

it('fetches only the pages it needs', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['next_cursor' => 'x'])),
        FakeResponse::json(membersPage(['c', 'd'], ['next_cursor' => 'y'])),
    );

    expect((new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)->take(3))->toHaveCount(3);
    $http->assertSentCount(2);
});

it('gives pages with their response', function (): void {
    $http = new FakeClient(FakeResponse::json(membersPage(['a']), headers: ['X-Total' => '1']));

    foreach ((new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)->pages() as $page) {
        expect($page->number)->toBe(1)
            ->and($page->items)->toHaveCount(1)
            ->and($page->response->header('X-Total'))->toBe('1');
    }
});

it('throws instead of looping when a page points at itself', function (): void {
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::json(membersPage(['a'], ['next_cursor' => 'same']))));

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)->all())->toThrow(PaginationException::class);
});

it('stops at maxPages', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next_cursor' => '1'])),
        FakeResponse::json(membersPage(['b'], ['next_cursor' => '2'])),
    );

    expect((new Acme('t', $http))->paginate(new ListMembers, new CursorPagination, maxPages: 2)->all())->toHaveCount(2);
    $http->assertSentCount(2);
});

it('throws the status exception for a page that fails', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a'], ['next_cursor' => 'gone'])),
        FakeResponse::problem(404, title: 'Cursor expired'),
    );

    expect(fn () => (new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)->all())->toThrow(NotFoundException::class);
});

it('is a lazy collection that fetches only the pages a chain needs', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['next_cursor' => 'x'])),
        FakeResponse::json(membersPage(['c', 'd'], ['next_cursor' => 'y'])),
        FakeResponse::json(membersPage(['e', 'f'])),
    );

    $member = (new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)
        ->first(fn (Member $member): bool => $member->id === 'c');

    expect($member?->id)->toBe('c');
    $http->assertSentCount(2);
});

it('maps and filters across pages', function (): void {
    $http = new FakeClient(
        FakeResponse::json(membersPage(['a', 'b'], ['next_cursor' => 'x'])),
        FakeResponse::json(membersPage(['c', 'd'])),
    );

    $ids = (new Acme('t', $http))->paginate(new ListMembers, new CursorPagination)
        ->reject(fn (Member $member): bool => $member->id === 'b')
        ->map(fn (Member $member): string => strtoupper($member->id))
        ->values()
        ->all();

    expect($ids)->toBe(['A', 'C', 'D']);
});

it('fetches the pages again when read again, and once when collected', function (): void {
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::json(membersPage(['a', 'b']))));
    $members = (new Acme('t', $http))->paginate(new ListMembers, new CursorPagination);

    $members->all();
    $members->all();
    $http->assertSentCount(2);

    $collected = $members->collect();
    $collected->first();
    $collected->count();
    $http->assertSentCount(3);
});
