<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\FormBody;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Bodies\Multipart;
use JustSteveKing\SdkToolbox\Bodies\Part;
use JustSteveKing\SdkToolbox\Bodies\RawBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\TooManyRequestsException;
use JustSteveKing\SdkToolbox\Http\ListFormat;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Idempotent;
use JustSteveKing\SdkToolbox\Requests\Request;
use JustSteveKing\SdkToolbox\Retries\IdempotencyKey;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * @return Request<Response>
 */
function post(Body $body, bool $idempotent = false, ?string $key = null): Request
{
    $request = $idempotent
        ? new readonly class($body, $key) implements HasBody, Idempotent, Request
        {
            public function __construct(private Body $body, private ?string $key) {}

            public function method(): Method
            {
                return Method::Post;
            }

            public function path(): string
            {
                return '/charges';
            }

            public function body(): Body
            {
                return $this->body;
            }

            public function idempotencyKey(): ?string
            {
                return $this->key;
            }

            public function result(Response $response): Response
            {
                return $response;
            }
        }
    : new readonly class($body) implements HasBody, Request
    {
        public function __construct(private Body $body) {}

        public function method(): Method
        {
            return Method::Post;
        }

        public function path(): string
        {
            return '/charges';
        }

        public function body(): Body
        {
            return $this->body;
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };

    return $request;
}

function connector(FakeClient $http, FakeTime $time, IdempotencyKey $keys = IdempotencyKey::Quoted): Connector
{
    return new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, idempotencyKeys: $keys);
}

it('retries a keyed POST with the same key and the whole body every time', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::empty(504), FakeResponse::json(['id' => 'ch_1'], 201));
    $time = new FakeTime;

    $response = connector($http, $time)->send(post(new JsonBody(['amount' => 500]), idempotent: true));

    expect($response->status())->toBe(201);
    $keys = array_map(fn ($r): string => $r->getHeaderLine('Idempotency-Key'), $http->sent());
    $bodies = array_map(fn ($r): string => (string) $r->getBody(), $http->sent());

    expect($keys)->toHaveCount(3)
        ->and(array_unique($keys))->toHaveCount(1)
        ->and($keys[0])->toMatch('/^"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"$/')
        ->and(array_unique($bodies))->toBe(['{"amount":500}']);
});

it('gives each send its own key', function (): void {
    $http = new FakeClient(FakeResponse::empty(201), FakeResponse::empty(201));
    $connector = connector($http, new FakeTime);

    $connector->send(post(new JsonBody(['a' => 1]), idempotent: true));
    $connector->send(post(new JsonBody(['a' => 1]), idempotent: true));

    expect($http->sent()[0]->getHeaderLine('Idempotency-Key'))->not->toBe($http->sent()[1]->getHeaderLine('Idempotency-Key'));
});

it('uses the request\'s own key, quoted or bare', function (): void {
    $http = new FakeClient(FakeResponse::empty(201), FakeResponse::empty(201));

    connector($http, new FakeTime)->send(post(new JsonBody([]), idempotent: true, key: 'order-42'));
    expect($http->last()?->getHeaderLine('Idempotency-Key'))->toBe('"order-42"');

    connector($http, new FakeTime, IdempotencyKey::Bare)->send(post(new JsonBody([]), idempotent: true, key: 'order-42'));
    expect($http->last()?->getHeaderLine('Idempotency-Key'))->toBe('order-42');
});

it('sends the key in the API\'s own header, and retries on it', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::json(['id' => 'em_1'], 201));
    $time = new FakeTime;
    $connector = new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, idempotencyKeys: IdempotencyKey::Bare, idempotencyHeader: 'X-Idempotency-Key');

    $connector->send(post(new JsonBody(['subject' => 'Hi']), idempotent: true, key: 'email-7'));

    expect(array_map(fn ($r): string => $r->getHeaderLine('X-Idempotency-Key'), $http->sent()))->toBe(['email-7', 'email-7'])
        ->and($http->last()?->hasHeader('Idempotency-Key'))->toBeFalse();
});

it('keeps the header in step when the retry policy is swapped', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::empty(201));
    $time = new FakeTime;
    $connector = (new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, idempotencyHeader: 'X-Idempotency-Key'))
        ->withRetry(RetryPolicy::standard()->withMaxAttempts(2));

    $connector->send(post(new JsonBody([]), idempotent: true));

    $http->assertSentCount(2);
});

it('refuses a header name that is not a token', function (): void {
    expect(fn () => new Connector('https://api.example.com', idempotencyHeader: 'Idempotency Key'))->toThrow(InvalidRequestException::class);
});

it('does not retry an unkeyed POST after a 502', function (): void {
    $http = new FakeClient(FakeResponse::empty(502));

    expect(fn () => connector($http, new FakeTime)->send(post(new JsonBody([]))))->toThrow(ServerException::class);
    $http->assertSentCount(1);
});

it('retries an unkeyed POST after a 429, honouring Retry-After', function (): void {
    $http = new FakeClient(FakeResponse::empty(429, ['Retry-After' => '3']), FakeResponse::empty(201));
    $time = new FakeTime;

    connector($http, $time)->send(post(new JsonBody([])));

    expect($time->sleeps())->toBe([3.0]);
});

it('never retries a body it cannot rewind', function (): void {
    $resource = fopen('php://temp', 'r+b');
    fwrite($resource, 'payload');
    rewind($resource);
    $stream = new class(Stream::create($resource)) implements StreamInterface
    {
        public function __construct(private StreamInterface $inner) {}

        public function isSeekable(): bool
        {
            return false;
        }

        public function __toString(): string
        {
            return $this->inner->getContents();
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return 0;
        }

        public function eof(): bool
        {
            return $this->inner->eof();
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            throw new RuntimeException('Not seekable');
        }

        public function rewind(): void
        {
            throw new RuntimeException('Not seekable');
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            return $this->inner->read($length);
        }

        public function getContents(): string
        {
            return $this->inner->getContents();
        }

        public function getMetadata(?string $key = null): mixed
        {
            return null;
        }
    };
    $http = new FakeClient(FakeResponse::empty(429), FakeResponse::empty(201));

    expect(fn () => connector($http, new FakeTime)->send(post(new RawBody($stream))))->toThrow(TooManyRequestsException::class);
    $http->assertSentCount(1);
});

it('puts Retry-After on the exception when it gives up', function (): void {
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::problem(429, title: 'Slow down', headers: ['Retry-After' => '2'])));

    try {
        connector($http, new FakeTime)->send(post(new JsonBody([])));
    } catch (TooManyRequestsException $e) {
        expect($e->retryAfter)->toBe(2.0);
    }

    $http->assertSentCount(3);
});

it('can be turned off', function (): void {
    $http = new FakeClient(FakeResponse::empty(503));

    expect(fn () => connector($http, new FakeTime)->withRetry(RetryPolicy::none())->send(post(new JsonBody([]))))->toThrow(ServerException::class);
    $http->assertSentCount(1);
});

it('refuses a key that cannot go in a header', function (): void {
    expect(fn () => IdempotencyKey::Bare->header('has space'))->toThrow(InvalidRequestException::class)
        ->and(IdempotencyKey::Quoted->header('a"b'))->toBe('"a\"b"');
});

/**
 * @return Request<Response>
 */
function keyedWithoutBody(): Request
{
    return new readonly class implements Idempotent, Request
    {
        public function method(): Method
        {
            return Method::Post;
        }

        public function path(): string
        {
            return '/charges/ch_1/capture';
        }

        public function idempotencyKey(): ?string
        {
            return null;
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };
}

function fieldConnector(FakeClient $http, FakeTime $time, string $field = 'dedupe_id'): Connector
{
    return new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, idempotencyField: $field);
}

it('puts the key in a JSON body field, the same one on every retry, and sends no header', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::empty(504), FakeResponse::json(['id' => 'ch_1'], 201));

    $response = fieldConnector($http, new FakeTime)->send(post(new JsonBody(['amount' => 500]), idempotent: true));

    $bodies = array_map(fn ($r): array => json_decode((string) $r->getBody(), true), $http->sent());

    expect($response->status())->toBe(201)
        ->and($bodies)->toHaveCount(3)
        ->and(array_unique(array_column($bodies, 'dedupe_id')))->toHaveCount(1)
        ->and($bodies[0]['amount'])->toBe(500)
        ->and($bodies[0]['dedupe_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    $http->assertNotSent(fn ($r): bool => $r->hasHeader('Idempotency-Key'));
});

it('puts the key in a form body field, and retries on it', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::empty(201));

    fieldConnector($http, new FakeTime)->send(post(new FormBody(['amount' => 500]), idempotent: true));

    $bodies = array_map(fn ($r): string => (string) $r->getBody(), $http->sent());

    expect($bodies)->toHaveCount(2)
        ->and(array_unique($bodies))->toHaveCount(1)
        ->and($bodies[0])->toMatch('/^amount=500&dedupe_id=[0-9a-f-]{36}$/')
        ->and($http->last()?->hasHeader('Idempotency-Key'))->toBeFalse();
});

it('uses the request\'s own key in the field, raw, replacing any value the body set', function (): void {
    $http = new FakeClient(FakeResponse::empty(201));
    $connector = new Connector('https://api.example.com', http: $http, idempotencyKeys: IdempotencyKey::Quoted, idempotencyField: 'dedupe_id');

    $connector->send(post(new JsonBody(['amount' => 500, 'dedupe_id' => 'stale']), idempotent: true, key: 'order-42'));

    expect(json_decode((string) $http->last()?->getBody(), true))->toBe(['amount' => 500, 'dedupe_id' => 'order-42']);
});

it('gives each send its own key in the field', function (): void {
    $http = new FakeClient(FakeResponse::empty(201), FakeResponse::empty(201));
    $connector = fieldConnector($http, new FakeTime);

    $connector->send(post(new JsonBody([]), idempotent: true));
    $connector->send(post(new JsonBody([]), idempotent: true));

    expect((string) $http->sent()[0]->getBody())->not->toBe((string) $http->sent()[1]->getBody());
});

it('keeps the field in step when the retry policy is swapped', function (): void {
    $http = new FakeClient(FakeResponse::empty(502), FakeResponse::empty(201));
    $time = new FakeTime;
    $connector = fieldConnector($http, $time)->withRetry(RetryPolicy::standard()->withMaxAttempts(2));

    $connector->send(post(new JsonBody([]), idempotent: true));

    $http->assertSentCount(2);
});

it('refuses a body the key cannot go in', function (Request $request): void {
    $http = new FakeClient(FakeResponse::empty(201));

    expect(fn () => fieldConnector($http, new FakeTime)->send($request))->toThrow(InvalidRequestException::class);
    $http->assertNothingSent();
})->with([
    'raw' => fn (): Request => post(new RawBody('<charge/>', 'application/xml'), idempotent: true),
    'multipart' => fn (): Request => post(new Multipart(Part::field('amount', '500')), idempotent: true),
    'a JSON list' => fn (): Request => post(new JsonBody([1, 2]), idempotent: true),
    'no body' => fn (): Request => keyedWithoutBody(),
]);

it('leaves requests that aren\'t Idempotent alone when a field is set', function (): void {
    $http = new FakeClient(FakeResponse::empty(201), FakeResponse::empty(502));
    $connector = fieldConnector($http, new FakeTime);

    $connector->send(post(new RawBody('<charge/>', 'application/xml')));

    expect((string) $http->last()?->getBody())->toBe('<charge/>')
        ->and(fn () => $connector->send(post(new JsonBody(['amount' => 1]))))->toThrow(ServerException::class);
    $http->assertSentCount(2);
});

it('refuses an empty field name', function (): void {
    expect(fn () => new Connector('https://api.example.com', http: new FakeClient, idempotencyField: ''))->toThrow(InvalidRequestException::class);
});

it('adds a field to a JSON body built from a JsonSerializable, and to a form body keeping its list format', function (): void {
    $order = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['amount' => 500];
        }
    };
    $streams = new Nyholm\Psr7\Factory\Psr17Factory;

    expect((string) (new JsonBody($order))->withField('dedupe_id', 'k')->stream($streams))->toBe('{"amount":500,"dedupe_id":"k"}')
        ->and((string) (new FormBody(['ids' => [1]], ListFormat::Repeated))->withField('dedupe_id', 'k')->stream($streams))->toBe('ids=1&dedupe_id=k');
});
