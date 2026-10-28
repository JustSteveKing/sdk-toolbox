# Requests and bodies

A request implements `Request<T>`: a method, a path and a `result()`. Everything else is opt-in through small interfaces, so a request carries only what it uses.

| Interface | Adds |
|---|---|
| `HasQuery` | `query(): array<string, mixed>` |
| `HasHeaders` | `headers(): array<string, string\|list<string>>` |
| `HasBody` | `body(): Body` |
| `Idempotent` | an Idempotency-Key ([Reliability](reliability.md#idempotency-key)) |
| `Conditional` | `If-Match` and friends ([HTTP semantics](http-semantics.md#conditional-requests)) |
| `HasPreferences` | a `Prefer` header ([HTTP semantics](http-semantics.md#preferences-and-202-polling)) |

## The base URI

Every path is appended to the connector's base URI, and the result has to stay under it.

| Base URI | Path | Request goes to |
|---|---|---|
| `https://api.acme.test/v2` | `/projects` | `https://api.acme.test/v2/projects` |
| `https://api.acme.test/v2` | `projects/1` | `https://api.acme.test/v2/projects/1` |
| `https://api.acme.test` | `/projects` | `https://api.acme.test/projects` |

Plain RFC 3986 resolution would turn `/projects` into `https://api.acme.test/projects`, dropping the `/v2`. It would also let a path like `//elsewhere.example` send the request, and its credentials, to another host. So these are refused with `UnsafeUriException` before anything is sent:

- absolute URLs, and `//host`;
- `..` segments, percent-encoded ones included;
- a `?` or `#` in the path (use `query()`);
- anything that isn't a valid URI, such as an unencoded space.

The base URI itself can't carry a query, a fragment or credentials.

Encode anything a caller supplies with `rawurlencode()` before it goes into a path. `/projects/'.rawurlencode('../../admin')` is one harmless segment.

## Query strings

```php
public function query(): array
{
    return [
        'q' => $this->search,
        'include_archived' => $this->archived,   // true or false
        'status' => $this->status,               // a backed enum sends its value
        'cursor' => $this->cursor,               // null is left out
    ];
}
```

PHP's `http_build_query()` sends `true` as `1` and drops `false`, which most APIs read as something else. The toolbox's `QueryString` encodes differently:

- `null` is left out;
- booleans are `true` and `false`;
- backed enums send their value;
- `Stringable`s are cast;
- arrays use PHP's bracket form.

Encoding is RFC 3986, so a space is `%20`. Anything else (an object, a resource) throws `InvalidRequestException`.

## Headers

Every request gets `Accept: application/json, application/problem+json`, and a User-Agent of your SDK's name followed by `sdk-toolbox/<version> php/<version>`. Set defaults for every request on the connector:

```php
parent::__construct(
    baseUri: 'https://api.acme.test',
    headers: ['Acme-Version' => '2026-10-01'],
    userAgent: 'acme-php/1.0',
);
```

A request's own `headers()` replace defaults of the same name. `$connector->withHeaders([...])` returns a copy with more defaults.

## Bodies

| Body | Content-Type | Notes |
|---|---|---|
| `new JsonBody($data)` | `application/json` | An empty array is sent as `{}`. Accepts arrays and `JsonSerializable` |
| `new FormBody($fields)` | `application/x-www-form-urlencoded` | Encoded the same way as a query string |
| `new RawBody($stringOrStream, $type)` | whatever you say | For XML, CSV, binary uploads |
| `new Multipart(...$parts)` | `multipart/form-data; boundary=...` | RFC 7578 |
| `new MergePatch($patch)` or `MergePatch::diff($before, $after)` | `application/merge-patch+json` | RFC 7396 |
| `JsonPatch::new()->...` | `application/json-patch+json` | RFC 6902 |

A body's Content-Type is set for you. A request's `headers()` can still override it.

### Multipart

```php
public function body(): Body
{
    return new Multipart(
        Part::field('title', 'Q3 report'),
        Part::file('file', fopen($this->path, 'rb'), 'q3.pdf', 'application/pdf'),
    );
}
```

`Part::file()` takes a stream, an open resource or a string of content. It never takes a file path, so an SDK can't be talked into reading a path someone else supplied. Parts are written into a `php://temp` stream, which moves to disk past 2MB, so a large upload isn't held in memory, and the stream can be rewound for a retry. Names and filenames are escaped so a `"` or a line break can't break the framing.

### JSON Merge Patch

Merge Patch is the simple way to send a partial update, with two rules that catch people out. `null` means "remove this member", so a merge patch can never set a value to null, and arrays are always replaced whole. `MergePatch::diff()` builds a patch from two versions of an object and enforces both:

```php
$patch = MergePatch::diff(
    ['title' => 'Draft', 'tags' => ['a', 'b'], 'owner' => ['id' => 1, 'name' => 'Ada']],
    ['title' => 'Final', 'tags' => ['a'], 'owner' => ['id' => 1]],
);

// ['title' => 'Final', 'tags' => ['a'], 'owner' => ['name' => null]]
```

Changing a member to `null` throws `InvalidRequestException`, naming the path, because the server would read it as "delete".

### JSON Patch

When you need operations rather than a new shape, JSON Patch says exactly what to do, and the server applies all of it or none of it. Put a `test` first to make the patch conditional:

```php
JsonPatch::new()
    ->test('/version', 3)
    ->replace('/name', 'Production')
    ->remove('/labels/0')
    ->add(JsonPointer::from('settings', 'a/b'), true);   // escapes to /settings/a~1b
```

Paths are checked as RFC 6901 JSON Pointers. `JsonPointer::from()` builds one from raw segments, escaping `~` and `/`.

## Sending

| Method | Returns | On a 4xx or 5xx |
|---|---|---|
| `send($request)` | what `result()` returns | throws |
| `response($request)` | the `Response`, whatever its status | returns it |
| `paginate($request, $strategy)` | a lazy `Paginator` | throws on the failing page |
| `await($request)` | `result()` of the finished operation | throws |

`Response` wraps the PSR-7 response:

- **status:** `status()`, `successful()`, `failed()`;
- **headers and body:** `header()`, `contentType()`, `body()`, `json()`;
- **values and objects:** `get('meta.next_cursor')` reads a value by dotted path, and `map()` and `mapList()` hydrate objects;
- **standards:** `problem()`, `etag()`, `lastModified()`, `rateLimit()`, `retryAfter()`, `deprecation()`, `preferenceApplied()`;
- **underneath:** `psr()` and `request()` for the PSR-7 objects.

`toPsr($request)` gives you the PSR-7 request the connector would send, before middleware and authentication, which is useful in tests.
