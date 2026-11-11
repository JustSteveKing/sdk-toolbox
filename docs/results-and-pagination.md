# Results and pagination

## Typed results

`result()` can build its object however you like. For anything bigger than a couple of fields, map the JSON onto your own readonly classes:

```php
public function result(Response $response): Member
{
    return $response->map(Member::class, at: 'data');
}
```

```php
final readonly class Member
{
    public function __construct(
        public string $id,
        public string $displayName,          // from "display_name"
        public Role $role,                   // a backed enum
        public DateTimeImmutable $joinedAt,  // from "joined_at"
        public ?string $avatarUrl = null,
    ) {}
}
```

`map()` and `mapList()` go through a `Hydrator`. Install `cuyz/valinor` and `ValinorHydrator` is used automatically. Its defaults:

- **Types are strict.** A string where an int belongs is an error, not a quiet cast.
- **Extra keys are ignored.** A field the API adds next year breaks nothing.
- **`snake_case` keys map to `camelCase` properties.**

A response that doesn't fit throws `HydrationException`, naming the field in the API's own terms: `display_name: Value 42 is not a valid string.` The message includes the offending value, which is response data, never a credential.

`at:` reaches into an envelope by dotted path (`data`, `data.items`), and `$response->get('meta.total')` reads a single value the same way. For data you'd rather not type, `$response->collect('data')` gives a [Collection](#collections).

To use something other than Valinor, implement `Hydrator` (two methods) and pass it to the connector as `hydrator:`. Without Valinor and without a hydrator, `map()` throws and says to install one.

### What Valinor costs

The toolbox only suggests Valinor, but once your SDK requires it, everyone who installs the SDK inherits it, so weigh it like any other dependency:

- **Size.** It's about 2 MB installed, more than the toolbox and a Guzzle client together. In the Resend case study it was the main reason the rebuild installed at more than twice the size of the official SDK.
- **PHP versions.** Valinor declares the PHP versions it supports one by one: 2.6.1 allows `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`. So an SDK that requires it can't be installed on a new PHP release until Valinor ships one that allows it, whatever the SDK's own constraint says. When PHP 8.6 arrives, check before you tell your users it's supported.
- **New fields are invisible.** Ignoring extra keys keeps a new field from breaking anything, and also keeps anyone from reading it until a property is added. Pair typed results with [untyped requests](#untyped-requests) so nobody's stuck waiting for a release.

None of this argues against typed results: strict types and a named field in the error are worth a lot. It argues for knowing the trade. For a small SDK with a handful of result classes, a static `fromArray()` on each class, reading values with type checks, needs no dependency at all, and `Hydrator` is a port, so you can switch later without touching a request.

## Untyped requests

Typed results always lag the API a little. Ignoring extra keys means a field the API added last week can't break anything, and it also means nobody can read that field until a property is added for it; an endpoint nobody has wrapped can't be called at all. So give your SDK's users a way round the types from the start. `Requests\Untyped\JsonRequest` takes arrays, written the way the API's own docs write the request, and returns the whole decoded body:

```php
use JustSteveKing\SdkToolbox\Requests\Untyped\JsonRequest;

$project = $acme->send(JsonRequest::get('/projects/'.rawurlencode($id), ['expand' => 'owner']));
$project['added_last_week'];

$acme->send(JsonRequest::post('/projects', ['name' => 'Website']));
$acme->send(JsonRequest::patch('/projects/'.rawurlencode($id), ['name' => 'Site']));
$acme->send(JsonRequest::delete('/projects/'.rawurlencode($id)));
```

They go through everything a typed request does: retries and `Retry-After`, the throttle, the cache, authentication, and your exception mapper, so a 404 is still a `NotFoundException`. `put()` and `patch()` send a JSON body, `delete()` sends none, and an empty answer comes back as `[]`. A body that isn't JSON throws `InvalidResponseException`.

**Only a POST given an idempotency key carries one:** `JsonRequest::post('/charges', $charge, idempotencyKey: $key)`. Then it may be retried after a failure the API might have acted on, with the same key every time ([Reliability](reliability.md#idempotency-key)). It's never generated here, as it is for an `Idempotent` request class, because a key is a promise the API has to keep: give one only where the API documents that it honours it.

How you expose them is up to the SDK: a method on the connector, or on each resource. The Resend case study puts typed resources and untyped calls side by side, and found the array form was what made the SDK keep up with the API between releases.

## Pagination

The same request works for one page and for all of them. Its `result()` reads one page into items:

```php
/**
 * @implements Request<list<Member>>
 */
final readonly class ListMembers implements Request
{
    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/members';
    }

    public function result(Response $response): array
    {
        return $response->mapList(Member::class, at: 'data');
    }
}
```

`send()` gives you the first page, and `paginate()` gives you every member:

```php
foreach ($acme->paginate(new ListMembers, new CursorPagination('meta.next_cursor')) as $member) {
    // ...
}
```

PHPStan knows that's a `Paginator<Member>`, so `$member` is a `Member`.

A paginator is also a [lazy collection](#collections), so the people using your SDK can filter and map across every page without writing the loop, and only the pages they need are fetched:

```php
$owner = $acme->paginate(new ListMembers, new CursorPagination('meta.next_cursor'))
    ->firstWhere('role', Role::Owner);
```

### Strategies

| Strategy | For an API that... |
|---|---|
| `new LinkPagination` | sends `Link: <...>; rel="next"` (RFC 8288), as GitHub does |
| `new NextUrlPagination('next')` | puts the next page's URL in the body, as Django REST Framework and Packagist's search do; relative URLs resolve against the request |
| `new CursorPagination('meta.next_cursor', parameter: 'cursor')` | puts a cursor in the body and wants it back as a query parameter |
| `new StartingAfterPagination` | says `has_more` and pages after the last item's ID, as Stripe does; with `hasMoreAt: null` and a `limit`, one that sends no flag and ends on a short page |
| `new OffsetPagination(limit: 100)` | takes `offset` and `limit`; a short page is the last |
| `new PagePagination(lastPageAt: 'meta.last_page')` | takes `page`; stops on an empty or short page, or at the last page the body names |

A strategy works on the PSR-7 request: `first()` shapes the first one and `next()` derives the next, so request classes never need to know they're paginated. Implement `Pagination` for an API that does something else.

Some APIs page after the last item's ID but send no "more" flag, and a page shorter than the limit is the last. Tell `StartingAfterPagination` there's no flag, and what the limit is:

```php
$acme->paginate(new ListEvents, new StartingAfterPagination(hasMoreAt: null, limit: 100));
```

- **The first request sends the limit,** as `limit=100`, and every next page keeps it. Name the parameter with `limitParameter`, or pass `limitParameter: null` when the limit is the API's own page size and isn't sent.
- **Paging stops on a page shorter than the limit, or an empty one,** so it never asks past the end: a full last page costs one request that comes back empty.
- **Without a limit** it can only stop on an empty page.
- **A limit below one** is refused with `InvalidRequestException`, as `OffsetPagination` refuses one.

With a flag, `limit` is optional and only sent; the flag alone decides when to stop, as before.

Some APIs' next URLs leave out options the first request sent, so the second page quietly answers a different question. `NextUrlPagination` puts back the parameters you name:

```php
$acme->paginate(new Search($query), new NextUrlPagination('pagination.next', carry: ['format', 'fresh']));
```

Each named parameter the request had and the next URL lacks is added as the request sent it. A value the API wrote into the next URL is never overridden, and credentials aren't carried: an authenticator such as `QueryKey` sets its own parameter on every page.

`Link` headers are parsed as RFC 8288 Appendix B sets out:

- relative targets are resolved against the request;
- links anchored on other resources are dropped;
- only the first `rel` counts;
- relations compare case-insensitively.

### What it guarantees

- **One page in memory.** Iterating a million items holds one page at a time. A test walks 100,000 items over 2,000 pages and checks memory stays flat. `all()` collects everything if you really want it.
- **Every page is a full request.** Retries, the throttle, your middleware and authentication all apply, and an error status on any page throws.
- **Next links stay on the API.** A `next` URL outside the base URI, from a `Link` header or the body, throws `UnsafeUriException` before it's sent.
- **No endless loops.** A page whose request repeats the one before throws `PaginationException`, and `maxPages` caps a longer cycle.
- **Only what's needed.** `take(10)` fetches the pages that hold ten items and stops, and so do `first()`, `contains()` and anything else that can stop early.
- **Read twice, fetched twice.** Iterating the paginator again sends the requests again. To use the same items more than once, `collect()` them into a `Collection`.

```php
$paginator = $acme->paginate(new ListMembers, new LinkPagination, maxPages: 50);

$first = $paginator->take(10)->all();

foreach ($paginator->pages() as $page) {
    $page->number;       // from 1
    $page->items;        // this page's members
    $page->response;     // its Response, headers and all
}
```

## Collections

`Collections\Collection` holds items in memory with the methods you'd otherwise write as loops, and `Collections\LazyCollection` has the same methods over items that arrive one at a time. There's no standard for this, so the names are the ones Laravel's collections use: they read the same in a Laravel app, a Symfony app or plain PHP, and nothing extra is installed.

You get one from three places:

```php
$response->collect('data');                  // the JSON at a path, as a Collection
$acme->paginate(new ListMembers, $strategy); // a Paginator, which is a LazyCollection
new Collection($members);                    // anything iterable
```

And then:

```php
$response->collect('data')
    ->where('status', 'active')
    ->sortByDesc('created_at')
    ->pluck('name', 'id');

$paginator
    ->filter(fn (Member $member): bool => $member->avatarUrl === null)
    ->map(fn (Member $member): string => $member->displayName)
    ->take(20)
    ->all();
```

Items can be decoded JSON arrays or typed objects. A string key reads either, by dotted path into nested data (`'source.name'`), and through an object's public properties only. A string is always a key, never a function name, so `pluck('count')` reads a field called `count`.

| | Collection | LazyCollection and Paginator |
|---|---|---|
| Filtering | `filter` `reject` `where` `whereIn` `partition` `unique` | `filter` `reject` `where` `whereIn` `unique` `takeWhile` |
| Finding | `first` `last` `firstWhere` `get` `has` `contains` `every` | `first` `firstWhere` `contains` `every` |
| Reshaping | `map` `flatMap` `mapWithKeys` `pluck` `keyBy` `groupBy` `values` `keys` `merge` | `map` `flatMap` `pluck` `values` `keys` |
| Ordering and slicing | `sortBy` `sortByDesc` `reverse` `take` `skip` `slice` `chunk` | `take` `skip` `chunk` |
| Reducing | `reduce` `sum` `avg` `min` `max` `count` `implode` `isEmpty` | `reduce` `sum` `count` `isEmpty` |
| Out | `all` `toArray` `lazy` and `json_encode()` | `all` `collect` `toArray` and `json_encode()` |

How they behave:

- **Immutable.** Every method returns a new collection, and writing through `$items['key'] = ...` throws `Errors\CollectionException`.
- **Keys are kept** by `filter()`, `sortBy()` and the rest, as PHP's array functions keep them, and `values()` renumbers.
- **`where()` with two arguments compares strictly,** so `where('id', '42')` doesn't match the integer `42`. With three it takes an operator: `= == === != <> !== < <= > >=`, where `=` and `==` are loose as in PHP.
- **Lazy means lazy.** A LazyCollection reads nothing until something asks for items, and stops reading as soon as it has what it needs. `count()` and `all()` read everything; on a paginator that's every page.
- **Each read starts again.** A LazyCollection calls its source afresh for every read, which for a paginator means fetching the pages again.

For SDK authors, a request whose items are only ever read can return a `Collection` from `result()` rather than an array:

```php
/** @implements Request<Collection<int, Member>> */
final readonly class ListTeamMembers implements Request
{
    // method(), path() ...
    public function result(Response $response): Collection
    {
        return new Collection($response->mapList(Member::class, at: 'data'));
    }
}
```

Keep `list<Member>` for a request you'll paginate: the paginator takes each page's items as they come and is already the collection.
