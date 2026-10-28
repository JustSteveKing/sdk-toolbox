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

`at:` reaches into an envelope by dotted path (`data`, `data.items`), and `$response->get('meta.total')` reads a single value the same way.

To use something other than Valinor, implement `Hydrator` (two methods) and pass it to the connector as `hydrator:`. Without Valinor and without a hydrator, `map()` throws and says to install one.

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

### Strategies

| Strategy | For an API that... |
|---|---|
| `new LinkPagination` | sends `Link: <...>; rel="next"` (RFC 8288), as GitHub does |
| `new CursorPagination('meta.next_cursor', parameter: 'cursor')` | puts a cursor in the body and wants it back as a query parameter |
| `new StartingAfterPagination` | says `has_more` and pages after the last item's ID, as Stripe does |
| `new OffsetPagination(limit: 100)` | takes `offset` and `limit`; a short page is the last |
| `new PagePagination(lastPageAt: 'meta.last_page')` | takes `page`; stops on an empty or short page, or at the last page the body names |

A strategy works on the PSR-7 request: `first()` shapes the first one and `next()` derives the next, so request classes never need to know they're paginated. Implement `Pagination` for an API that does something else.

`Link` headers are parsed as RFC 8288 Appendix B sets out:

- relative targets are resolved against the request;
- links anchored on other resources are dropped;
- only the first `rel` counts;
- relations compare case-insensitively.

### What it guarantees

- **One page in memory.** Iterating a million items holds one page at a time. A test walks 100,000 items over 2,000 pages and checks memory stays flat. `all()` collects everything if you really want it.
- **Every page is a full request.** Retries, the throttle, your middleware and authentication all apply, and an error status on any page throws.
- **Next links stay on the API.** A `next` URL outside the base URI throws `UnsafeUriException` before it's sent.
- **No endless loops.** A page whose request repeats the one before throws `PaginationException`, and `maxPages` caps a longer cycle.
- **Only what's needed.** `take(10)` fetches the pages that hold ten items and stops.

```php
$paginator = $acme->paginate(new ListMembers, new LinkPagination, maxPages: 50);

$first = $paginator->take(10);

foreach ($paginator->pages() as $page) {
    $page->number;       // from 1
    $page->items;        // this page's members
    $page->response;     // its Response, headers and all
}
```
