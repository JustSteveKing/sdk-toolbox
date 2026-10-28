# Porting an existing SDK

The toolbox was proved by moving a real SDK onto it: einvoicing/sdk, a PSR-18 client for einvoicing.dev of about 1,400 lines, with eight resources, twelve endpoints and its own exception hierarchy.

- **Its public API didn't change:** the client, the resources, the response objects and the exception classes, properties included.
- **26 of its 28 tests passed unchanged,** with one line added to the test helper. The other two tested internals: private properties, and the exact User-Agent string.
- **What it gained without writing it:** safe retries, refusal to send its key over plain HTTP, redaction in exceptions, RFC 9457 parsing and logging.

## The steps

### 1. The client extends Connector

Keep your client's constructor signature, and pass everything to the parent:

```php
final class Client extends Connector
{
    public function __construct(
        #[SensitiveParameter] string $key,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        string $baseUrl = 'https://api.einvoicing.dev',
        RetryStrategy $retry = new RetryPolicy,
    ) {
        parent::__construct(
            baseUri: $baseUrl,
            auth: new BearerToken($key),
            http: $http,
            headers: ['Accept' => 'application/json'],
            userAgent: 'einvoicing-php/'.self::version(),
            exceptions: new Problems,
            requests: $requests,
            streams: $streams,
            insecure: in_array(parse_url($baseUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true),
            retry: $retry,
        );
    }
}
```

Two judgement calls were made there:

- **`insecure`** is allowed only for loopback hosts, because the old client let tests point at `http://localhost:8787`.
- **`Accept`** is pinned to what the old client sent, so nothing about the wire format changed.

If your package has its own `TransportException`, override `send()` to wrap the toolbox's:

```php
public function send(Request $request): mixed
{
    try {
        return parent::send($request);
    } catch (\JustSteveKing\SdkToolbox\Errors\TransportException $e) {
        throw new TransportException($e->getMessage(), previous: $e);
    }
}
```

### 2. Each endpoint becomes a Request

Each resource method shrinks to one line:

```php
public function find(string $id): Participant
{
    return $this->client->send(new FindParticipant($id));
}
```

The response classes kept their own hand-written `fromArray()`, since the toolbox's hydrator is optional.

### 3. Exceptions extend HttpException

Your exception classes keep their names and their place in your hierarchy (keep implementing your marker interface), but extend `HttpException` or `ClientException`. A custom `ExceptionMapper` picks the subclass. Two tools keep their public surface the same:

- **`describe()`:** override it to keep the old message wording. The constructor is final, so this is where wording changes.
- **Properties computed from `$this->problem`:** on PHP 8.4 or newer, property hooks are the neatest way (`public string $type { get => $this->problem->type ?? 'about:blank'; }`); on 8.3, use methods.

### 4. Tests

The tests didn't need to change, apart from the helper that builds the client. It passes `retry: RetryPolicy::none()`, because a test that queues exactly one error expects exactly one attempt. Add a test that a retry does happen, and one that a POST after a 502 doesn't.

## The cost

Request classes. Twelve endpoints came to about 470 lines of them, while the HTTP plumbing they replaced shrank by about 140. The behaviour the SDK gained is the point; the line count went up.
