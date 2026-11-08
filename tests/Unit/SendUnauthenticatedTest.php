<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\RawBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Events\RequestSending;
use JustSteveKing\SdkToolbox\Events\ResponseReceived;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\HasHeaders;
use JustSteveKing\SdkToolbox\Requests\Request;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

const PRESIGNED = 'https://uploads.storage.test/bucket/report.pdf?X-Amz-Credential=AKID%2F20261108&X-Amz-Signature=abc123&X-Goog-Signature=def456&expires=600';

/**
 * A PUT of some bytes, as an upload to a pre-signed URL is.
 *
 * @param  array<string, string>  $headers
 * @return Request<Response>
 */
function upload(array $headers = []): Request
{
    return new readonly class($headers) implements HasBody, HasHeaders, Request
    {
        /**
         * @param  array<string, string>  $headers
         */
        public function __construct(private array $headers) {}

        public function method(): Method
        {
            return Method::Put;
        }

        public function path(): string
        {
            return '/ignored';
        }

        public function headers(): array
        {
            return $this->headers;
        }

        public function body(): Body
        {
            return new RawBody('%PDF-1.7', 'application/pdf');
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };
}

it('sends no credentials and no connector headers to the URI it is given', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', auth: new BearerToken('t'), http: $http, headers: ['X-Api-Key' => 'k', 'Acme-Version' => '2026-10-01'], userAgent: 'acme-php/1.0');

    $response = $connector->sendUnauthenticated(PRESIGNED, upload(['Content-MD5' => 'abc==']));
    $sent = $http->last();

    expect($response->status())->toBe(200)
        ->and((string) $sent?->getUri())->toBe(PRESIGNED)
        ->and($sent?->getMethod())->toBe('PUT')
        ->and($sent?->hasHeader('Authorization'))->toBeFalse()
        ->and($sent?->hasHeader('X-Api-Key'))->toBeFalse()
        ->and($sent?->hasHeader('Acme-Version'))->toBeFalse()
        ->and($sent?->hasHeader('Accept'))->toBeFalse()
        ->and($sent?->getHeaderLine('Content-MD5'))->toBe('abc==')
        ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/pdf')
        ->and($sent?->getHeaderLine('User-Agent'))->toStartWith('acme-php/1.0 sdk-toolbox/')
        ->and((string) $sent?->getBody())->toBe('%PDF-1.7');
});

it('runs none of the connector\'s middleware', function (): void {
    $signs = new class implements Middleware
    {
        public int $ran = 0;

        public function handle(RequestInterface $request, Next $next): ResponseInterface
        {
            $this->ran++;

            return $next->handle($request->withHeader('Signature', 'sig1=:abc:'));
        }
    };
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = (new Connector('https://api.example.com', http: $http))->withMiddleware($signs);

    $connector->sendUnauthenticated(PRESIGNED, upload());

    expect($signs->ran)->toBe(0)
        ->and($http->last()?->hasHeader('Signature'))->toBeFalse();
});

it('leaves a query-key authenticator\'s parameter off the URI', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', 'top-secret'), http: $http);

    $connector->sendUnauthenticated(PRESIGNED, upload());

    expect((string) $http->last()?->getUri())->not->toContain('top-secret');
});

it('refuses a relative URI, plain http and user info', function (string $uri): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient);

    expect(fn () => $connector->sendUnauthenticated($uri, upload()))->toThrow(UnsafeUriException::class);
})->with([
    'relative' => '/bucket/report.pdf',
    'network-path' => '//uploads.storage.test/report.pdf',
    'http' => 'http://uploads.storage.test/report.pdf',
    'user info' => 'https://me:pw@uploads.storage.test/report.pdf',
    'not a URI' => 'https://uploads.storage.test/a report.pdf',
]);

it('allows plain http on an insecure connector', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = new Connector('http://localhost:8080', http: $http, insecure: true);

    $connector->sendUnauthenticated('http://localhost:9000/bucket/report.pdf', upload());

    expect((string) $http->last()?->getUri())->toBe('http://localhost:9000/bucket/report.pdf');
});

it('refuses a request that adds a query to the URI', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient);

    expect(fn () => $connector->sendUnauthenticated(PRESIGNED, ping(['part' => 2])))->toThrow(InvalidRequestException::class)
        ->and(fn () => $connector->sendUnauthenticated(PRESIGNED, ping(['part' => null])))->not->toThrow(InvalidRequestException::class);
});

it('redacts the pre-signed URI in events and logs', function (): void {
    $events = new CollectingDispatcher;
    $logger = new CollectingLogger;
    $http = new FakeClient(FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', http: $http, events: $events, logger: $logger);

    $connector->sendUnauthenticated(PRESIGNED, upload());

    $uris = array_map(fn (object $e): string => $e instanceof RequestSending || $e instanceof ResponseReceived ? $e->request->uri : '', $events->events);

    expect($uris)->toHaveCount(2)
        ->and($uris[0])->toBe('https://uploads.storage.test/bucket/report.pdf?X-Amz-Credential=%5Bredacted%5D&X-Amz-Signature=%5Bredacted%5D&X-Goog-Signature=%5Bredacted%5D&expires=600')
        ->and(implode("\n", $logger->messages()))->not->toContain('abc123')->not->toContain('AKID');
});

it('retries as send() does', function (): void {
    $http = new FakeClient(FakeResponse::empty(503), FakeResponse::empty(200));
    $time = new FakeTime;
    $connector = new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time);

    expect($connector->sendUnauthenticated(PRESIGNED, upload())->status())->toBe(200);
    $http->assertSentCount(2);
});

it('returns an error status rather than throwing, as response() does', function (): void {
    $http = new FakeClient(FakeResponse::empty(403));
    $connector = new Connector('https://api.example.com', http: $http);

    expect($connector->sendUnauthenticated(PRESIGNED, upload())->status())->toBe(403);
});

it('still keeps every other request under the base URI, with its credentials', function (): void {
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com/v2', auth: new BearerToken('t'), http: $http, headers: ['Acme-Version' => '2026-10-01']);

    $connector->sendUnauthenticated(PRESIGNED, upload());
    $connector->response(ping());

    expect((string) $http->last()?->getUri())->toBe('https://api.example.com/v2/ping')
        ->and($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer t')
        ->and($http->last()?->getHeaderLine('Acme-Version'))->toBe('2026-10-01')
        ->and(fn () => $connector->response(deleteOf('https://uploads.storage.test/report.pdf')))->toThrow(UnsafeUriException::class);
});
