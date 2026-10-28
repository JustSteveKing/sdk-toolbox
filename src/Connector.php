<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JustSteveKing\SdkToolbox\Auth\Authenticator;
use JustSteveKing\SdkToolbox\Errors\ExceptionMapper;
use JustSteveKing\SdkToolbox\Errors\HttpException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\StatusExceptions;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Http\BaseUri;
use JustSteveKing\SdkToolbox\Http\QueryString;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Middleware\Pipeline;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\HasHeaders;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;
use NoDiscard;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * An API: where it is, how to authenticate, and how to send requests to it.
 *
 * Extend it for your SDK and call the parent constructor with the API's
 * details, or hold one; either works, because nothing here relies on being
 * extended. It's immutable: the `with*()` methods return a changed copy.
 *
 * Requests go through the middleware in order, then the authenticator, then
 * the PSR-18 client. Bring your application's client, or leave it out and
 * php-http/discovery finds an installed one.
 */
class Connector
{
    public const string VERSION = '0.1.0-dev';

    private readonly BaseUri $baseUri;

    private readonly ClientInterface $http;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    private readonly Redactor $redactor;

    /**
     * @param  string  $baseUri  every request path is appended to this, and can't leave it
     * @param  list<Middleware>  $middleware  run in order, before authentication
     * @param  array<string, string|list<string>>  $headers  sent with every request; a request's own headers replace them
     * @param  string|null  $userAgent  your SDK's name and version, put ahead of the toolbox's own
     * @param  bool  $insecure  allow credentials over plain `http://`, which is refused by default
     */
    public function __construct(
        string $baseUri,
        private readonly ?Authenticator $auth = null,
        ?ClientInterface $http = null,
        private readonly array $middleware = [],
        private readonly array $headers = [],
        private readonly ?string $userAgent = null,
        private readonly ExceptionMapper $exceptions = new StatusExceptions,
        ?Redactor $redactor = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        bool $insecure = false,
    ) {
        $this->baseUri = new BaseUri($baseUri);

        // draft-ietf-httpapi-privacy: no credentials over an insecure channel
        // unless the caller has said, in so many words, that it's fine.
        if ($auth !== null && $this->baseUri->scheme() !== 'https' && ! $insecure) {
            throw new InvalidRequestException("Refusing to send credentials to [{$this->baseUri->toString()}] without TLS. Pass `insecure: true` if that's intended, for a local server.");
        }

        $sensitive = $auth?->sensitive() ?? ['headers' => [], 'query' => []];

        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->redactor = ($redactor ?? new Redactor)->with($sensitive['headers'], $sensitive['query']);
    }

    /**
     * Sends a request and returns what its `result()` makes of the response.
     *
     * @template TResult
     *
     * @param  Request<TResult>  $request
     * @return TResult
     *
     * @throws HttpException for a 4xx or 5xx
     * @throws TransportException when no response came back
     */
    public function send(Request $request): mixed
    {
        $response = $this->response($request);

        if ($response->failed()) {
            throw $this->exceptions->map($response, $this->redactor);
        }

        return $request->result($response);
    }

    /**
     * Sends a request and returns the response as it came, error statuses
     * included.
     *
     * @param  Request<mixed>  $request
     *
     * @throws TransportException when no response came back
     */
    public function response(Request $request): Response
    {
        $psr = $this->toPsr($request);

        try {
            $answer = $this->pipeline()->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::from($e, $psr, $this->redactor);
        }

        return new Response($answer, $psr);
    }

    /**
     * The PSR-7 request a Request becomes, before middleware and
     * authentication.
     *
     * @param  Request<mixed>  $request
     */
    public function toPsr(Request $request): RequestInterface
    {
        $uri = $this->baseUri->resolve($request->path());

        if ($request instanceof HasQuery) {
            $query = QueryString::build($request->query());
            $uri = $uri->withQuery($query === '' ? null : $query);
        }

        $psr = $this->requests->createRequest($request->method()->value, $uri->toRawString());

        $headers = [
            'Accept' => 'application/json, application/problem+json',
            'User-Agent' => $this->userAgentString(),
            ...$this->headers,
            ...($request instanceof HasHeaders ? $request->headers() : []),
        ];

        if ($request instanceof HasBody) {
            $body = $request->body();
            $psr = $psr->withBody($body->stream($this->streams));
            $headers = ['Content-Type' => $body->contentType(), ...$headers];
        }

        foreach ($headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        return $psr;
    }

    #[NoDiscard]
    public function withMiddleware(Middleware ...$middleware): static
    {
        return clone ($this, ['middleware' => [...$this->middleware, ...array_values($middleware)]]);
    }

    #[NoDiscard]
    public function withHttpClient(ClientInterface $http): static
    {
        return clone ($this, ['http' => $http]);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    #[NoDiscard]
    public function withHeaders(array $headers): static
    {
        return clone ($this, ['headers' => [...$this->headers, ...$headers]]);
    }

    public function baseUri(): string
    {
        return $this->baseUri->toString();
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUri' => $this->baseUri->toString(),
            'auth' => $this->auth === null ? null : $this->auth::class,
            'http' => $this->http::class,
            'middleware' => array_map(static fn (Middleware $m): string => $m::class, $this->middleware),
        ];
    }

    private function pipeline(): Pipeline
    {
        $middleware = $this->middleware;

        if ($this->auth !== null) {
            $auth = $this->auth;
            $middleware[] = new class($auth) implements Middleware
            {
                public function __construct(private readonly Authenticator $auth) {}

                public function handle(RequestInterface $request, Next $next): ResponseInterface
                {
                    return $next->handle($this->auth->authenticate($request));
                }
            };
        }

        return new Pipeline($this->http, $middleware);
    }

    private function userAgentString(): string
    {
        $toolbox = 'sdk-toolbox/'.self::VERSION.' php/'.PHP_VERSION;

        return $this->userAgent === null ? $toolbox : "{$this->userAgent} {$toolbox}";
    }
}
