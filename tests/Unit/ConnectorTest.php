<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\BasicAuth;
use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Auth\HeaderKey;
use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Tag implements Middleware
{
    /** @var list<string> */
    public static array $order = [];

    public function __construct(private readonly string $name) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        self::$order[] = "{$this->name} in";
        $response = $next->handle($request->withAddedHeader('X-Tag', $this->name));
        self::$order[] = "{$this->name} out";

        return $response->withAddedHeader('X-Seen', $this->name);
    }
}

it('runs middleware in order, and authentication after all of it', function (): void {
    Tag::$order = [];
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = (new Connector('https://api.example.com', auth: new BearerToken('t'), http: $http))
        ->withMiddleware(new Tag('first'), new Tag('second'));

    $response = $connector->response(ping());

    expect(Tag::$order)->toBe(['first in', 'second in', 'second out', 'first out'])
        ->and($http->last()?->getHeader('X-Tag'))->toBe(['first', 'second'])
        ->and($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer t')
        ->and($response->psr()->getHeader('X-Seen'))->toBe(['second', 'first']);
});

it('is immutable', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient);
    $changed = $connector->withHeaders(['X-Api-Version' => '2026-10-01']);

    expect($changed)->not->toBe($connector)
        ->and($connector->toPsr(ping())->hasHeader('X-Api-Version'))->toBeFalse()
        ->and($changed->toPsr(ping())->getHeaderLine('X-Api-Version'))->toBe('2026-10-01');
});

it('lets a request replace default headers', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient, headers: ['Accept' => 'application/json']);

    expect($connector->toPsr(ping(headers: ['Accept' => 'text/csv']))->getHeaderLine('Accept'))->toBe('text/csv');
});

it('returns error responses from response() and throws them from send()', function (): void {
    $http = new FakeClient(FakeResponse::empty(503), FakeResponse::empty(503));
    $connector = new Connector('https://api.example.com', http: $http, retry: RetryPolicy::none());

    expect($connector->response(ping())->status())->toBe(503)
        ->and(fn () => $connector->send(ping()))->toThrow(ServerException::class);
});

it('refuses to send credentials without TLS unless told to', function (): void {
    expect(fn () => new Connector('http://api.example.com', auth: new BearerToken('t'), http: new FakeClient))
        ->toThrow(InvalidRequestException::class);

    expect((new Connector('http://localhost:8080', auth: new BearerToken('t'), http: new FakeClient, insecure: true))->baseUri())
        ->toBe('http://localhost:8080/');
});

it('authenticates with the usual schemes', function (): void {
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200), FakeResponse::empty(200));

    (new Connector('https://api.example.com', auth: new BasicAuth('steve', 'pw'), http: $http))->response(ping());
    expect($http->last()?->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('steve:pw'));

    (new Connector('https://api.example.com', auth: new HeaderKey('X-Api-Key', 'k1'), http: $http))->response(ping());
    expect($http->last()?->getHeaderLine('X-Api-Key'))->toBe('k1');

    (new Connector('https://api.example.com', auth: new QueryKey('api_key', 'k2'), http: $http))->response(ping(['q' => 'a', 'api_key' => 'someone-elses']));
    expect($http->last()?->getUri()->getQuery())->toBe('q=a&api_key=k2');
});

it('masks a custom key header and query parameter in transport errors', function (): void {
    $http = new FakeClient(FakeResponse::networkError('failed for https://api.example.com/ping?key_param=k2'));
    $connector = new Connector('https://api.example.com', auth: new QueryKey('key_param', 'k2'), http: $http, retry: RetryPolicy::none());

    try {
        $connector->response(ping());
    } catch (TransportException $e) {
        expect($e->getMessage())->not->toContain('k2')
            ->and((string) $e->request)->toBe('GET https://api.example.com/ping?key_param=%5Bredacted%5D');
    }
});

it('refuses a Basic user-id with a colon (RFC 7617)', function (): void {
    expect(fn () => new BasicAuth('a:b', 'pw'))->toThrow(InvalidRequestException::class);
});
