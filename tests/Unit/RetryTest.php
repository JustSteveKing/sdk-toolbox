<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Bodies\RawBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\TooManyRequestsException;
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
    expect(fn () => IdempotencyKey::Bare->header('has space'))->toThrow(JustSteveKing\SdkToolbox\Errors\InvalidRequestException::class)
        ->and(IdempotencyKey::Quoted->header('a"b'))->toBe('"a\"b"');
});
