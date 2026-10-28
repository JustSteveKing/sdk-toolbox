# Results and pagination

## Typed results

```php
public function result(Response $response): Member
{
    return $response->map(Member::class, at: 'data');
}
```

`map()` and `mapList()` use `Hydration\ValinorHydrator` when `cuyz/valinor` is installed.

- **Types are strict,** extra keys are ignored, and `snake_case` keys fill `camelCase` constructor parameters.
- **Result classes** are `final readonly` with constructor-promoted public properties. Optional fields get defaults.
- **A mismatch** throws `Errors\HydrationException`, naming the API's key.
- **Without Valinor,** write a static `fromArray()` instead, reading each value with a type check rather than a cast.

## Pagination

A list request returns one page's items from `result()`, typed as `Request<list<Item>>`:

```php
/** @implements Request<list<Member>> */
final readonly class ListMembers implements Request
{
    // method(), path() ...
    public function result(Response $response): array
    {
        return $response->mapList(Member::class, at: 'data');
    }
}
```

Then:

```php
foreach ($sdk->paginate(new ListMembers, new Pagination\CursorPagination('meta.next_cursor')) as $member) {}
```

Expose it from a resource as `public function all(): Paginator` (`@return Paginator<Member>`), not as an array.

| The API... | Strategy |
|---|---|
| sends `Link: <...>; rel="next"` | `Pagination\LinkPagination` |
| returns a cursor in the body, wants it as `?cursor=` | `Pagination\CursorPagination('path.to.cursor', parameter: 'cursor')` |
| returns `has_more` and pages by `starting_after` | `Pagination\StartingAfterPagination` |
| takes `offset` and `limit` | `Pagination\OffsetPagination(limit: 100)` |
| takes `page` | `Pagination\PagePagination(perPage: 50, lastPageAt: 'meta.last_page')` |

Implement `Pagination\Pagination` (`first()` and `next()` on the PSR-7 request) for anything else.

Rules:

- Don't collect pages into arrays yourself: the paginator holds one page in memory.
- Use `->take(n)` for "the first n", and `->pages()` when the caller needs each page's response.
- Pass `maxPages:` when an API's paging can't be trusted to end.
