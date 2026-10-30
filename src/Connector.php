<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JustSteveKing\SdkToolbox\Auth\Authenticate;
use JustSteveKing\SdkToolbox\Auth\Authenticator;
use JustSteveKing\SdkToolbox\Caching\HttpCache;
use JustSteveKing\SdkToolbox\Deprecation\Deprecations;
use JustSteveKing\SdkToolbox\Errors\ExceptionMapper;
use JustSteveKing\SdkToolbox\Errors\HttpException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidResponseException;
use JustSteveKing\SdkToolbox\Errors\OperationFailedException;
use JustSteveKing\SdkToolbox\Errors\OperationTimeoutException;
use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Errors\StatusExceptions;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Http\BaseUri;
use JustSteveKing\SdkToolbox\Http\QueryString;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Http\Uri;
use JustSteveKing\SdkToolbox\Hydration\Hydrator;
use JustSteveKing\SdkToolbox\Hydration\ValinorHydrator;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Middleware\Pipeline;
use JustSteveKing\SdkToolbox\Observability\Attempts;
use JustSteveKing\SdkToolbox\Observability\Observe;
use JustSteveKing\SdkToolbox\Observability\OpenTelemetrySpans;
use JustSteveKing\SdkToolbox\Observability\Telemetry;
use JustSteveKing\SdkToolbox\Observability\TraceContext;
use JustSteveKing\SdkToolbox\Pagination\Pagination;
use JustSteveKing\SdkToolbox\Pagination\Paginator;
use JustSteveKing\SdkToolbox\RateLimits\Throttle;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Requests\Conditional;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\HasHeaders;
use JustSteveKing\SdkToolbox\Requests\HasPreferences;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Idempotent;
use JustSteveKing\SdkToolbox\Requests\Request;
use JustSteveKing\SdkToolbox\Retries\IdempotencyKey;
use JustSteveKing\SdkToolbox\Retries\Retry;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Retries\RetryStrategy;
use JustSteveKing\SdkToolbox\Time\Seconds;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use JustSteveKing\SdkToolbox\Time\SystemSleeper;
use NoDiscard;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * An API: where it is, how to authenticate, and how to send requests to it.
 *
 * Extend it for your SDK and call the parent constructor with the API's
 * details, or hold one; either works, because nothing here relies on being
 * extended. It's immutable: the `with*()` methods return a changed copy.
 *
 * Requests go through the retry policy, the throttle and deprecation
 * watcher if there are any, the middleware in order, the authenticator,
 * the HTTP cache if there is one, the instrumentation and tracing, then
 * the PSR-18 client. So each
 * retry is throttled, passes through every middleware and is
 * authenticated afresh. Bring your application's client, or leave it out and
 * php-http/discovery finds an installed one.
 */
class Connector
{
    public const string VERSION = '0.0.6';

    private readonly BaseUri $baseUri;

    private ClientInterface $http;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    private readonly Redactor $redactor;

    private readonly ?Hydrator $hydrator;

    private readonly ?HttpCache $cache;

    private readonly Telemetry $telemetry;

    /**
     * @param  string  $baseUri  every request path is appended to this, and can't leave it
     * @param  list<Middleware>  $middleware  run in order, before authentication
     * @param  array<string, string|list<string>>  $headers  sent with every request; a request's own headers replace them
     * @param  string|null  $userAgent  your SDK's name and version, put ahead of the toolbox's own
     * @param  bool  $insecure  allow credentials over plain `http://`, which is refused by default
     * @param  RetryStrategy  $retry  RetryPolicy::standard() unless told otherwise; RetryPolicy::none() turns retries off
     * @param  Throttle|null  $throttle  wait for a spent rate limit to reset before sending, instead of collecting 429s
     * @param  IdempotencyKey  $idempotencyKeys  how Idempotent requests format their key
     * @param  string  $idempotencyHeader  the header the key goes in: the draft's `Idempotency-Key` unless the API uses another
     * @param  Hydrator|null  $hydrator  maps responses to objects; ValinorHydrator when cuyz/valinor is installed
     * @param  CacheInterface|null  $cache  a PSR-16 store for an RFC 9111 private HTTP cache; no caching without one
     * @param  Deprecations|null  $deprecations  told about Deprecation and Sunset headers, once per endpoint
     * @param  LoggerInterface|null  $logger  every event logged through PSR-3, redacted
     * @param  EventDispatcherInterface|null  $events  every event dispatched through PSR-14
     * @param  TraceContext|OpenTelemetrySpans|null  $tracing  trace context on every attempt, or an OpenTelemetry span for each
     */
    public function __construct(
        string $baseUri,
        private readonly ?Authenticator $auth = null,
        ?ClientInterface $http = null,
        private array $middleware = [],
        private array $headers = [],
        private readonly ?string $userAgent = null,
        private readonly ExceptionMapper $exceptions = new StatusExceptions,
        ?Redactor $redactor = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        bool $insecure = false,
        private RetryStrategy $retry = new RetryPolicy,
        private readonly ?Throttle $throttle = null,
        private readonly IdempotencyKey $idempotencyKeys = IdempotencyKey::Quoted,
        private readonly ClockInterface $clock = new SystemClock,
        private readonly Sleeper $sleeper = new SystemSleeper,
        ?Hydrator $hydrator = null,
        ?CacheInterface $cache = null,
        private readonly ?Deprecations $deprecations = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
        private readonly TraceContext|OpenTelemetrySpans|null $tracing = null,
        private readonly string $idempotencyHeader = 'Idempotency-Key',
    ) {
        $this->baseUri = new BaseUri($baseUri);

        // A field name is a token (RFC 9110 section 5.1).
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $idempotencyHeader) !== 1) {
            throw new InvalidRequestException("[{$idempotencyHeader}] isn't a valid header name.");
        }

        $this->retry = $this->keyedBy($retry);

        // draft-ietf-httpapi-privacy: no credentials over an insecure channel
        // unless the caller has said, in so many words, that it's fine.
        if ($auth !== null && $this->baseUri->scheme() !== 'https' && ! $insecure) {
            throw new InvalidRequestException("Refusing to send credentials to [{$this->baseUri->toString()}] without TLS. Pass `insecure: true` if that's intended, for a local server.");
        }

        $sensitive = $auth?->sensitive() ?? ['headers' => [], 'query' => []];

        $this->hydrator = $hydrator ?? (ValinorHydrator::available() ? new ValinorHydrator : null);
        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->redactor = ($redactor ?? new Redactor)->with($sensitive['headers'], $sensitive['query']);
        $this->telemetry = new Telemetry($events, $logger, $this->redactor);
        $this->cache = $cache === null ? null : new HttpCache($cache, $this->clock, $this->redactor, telemetry: $this->telemetry);
        $this->deprecations?->useTelemetry($this->telemetry);
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
        return $this->dispatch($this->toPsr($request));
    }

    /**
     * Sends a request that may be answered asynchronously, and waits for
     * the result.
     *
     * A 202 Accepted with a `Location` is an operation in progress, and
     * `Location` is where its status lives. That monitor is polled with
     * GET, waiting as long as `Retry-After` says (or `interval`), for as long
     * as it says the operation is still going: a 202, or a 200 that carries
     * `Retry-After`, which is how a monitor whose own GET succeeded says
     * "ask again then" (api-toolbox's does this). A 303 See Other there
     * points at the result, which is fetched; any other 200 is the result
     * itself, unless it reports the operation failed. Either way, the
     * original request's result() reads it. Any other response is returned
     * to result() as it is, so an API that answers synchronously works too.
     *
     * The monitor and result URIs have to be under the base URI, like any
     * other request. RFC 7240 section 4.1 and RFC 9110 section 15.3.3 name
     * the 202 and its monitor but leave the monitor's answers to APIs.
     *
     * @template TResult
     *
     * @param  Request<TResult>  $request
     * @return TResult
     *
     * @throws OperationTimeoutException when it's still running after `timeout` seconds
     * @throws OperationFailedException when the monitor reports the operation failed
     */
    public function await(Request $request, float $timeout = 300.0, float $interval = 2.0): mixed
    {
        $started = $this->clock->now();
        $response = $this->checked($this->toPsr($request));
        $location = $response->status() === 202 ? ($response->header('Location') ?? $response->header('Content-Location')) : null;

        if ($location === null) {
            return $request->result($response);
        }

        $monitor = $this->follow($response, $location);

        while (true) {
            $wait = $response->retryAfter() ?? $interval;
            $waited = Seconds::between($started, $this->clock->now());

            if ($waited + $wait > $timeout) {
                throw new OperationTimeoutException($this->redactor->uri($monitor), $waited);
            }

            $this->sleeper->sleep($wait);
            $response = $this->checked($this->requests->createRequest('GET', $monitor));

            if ($response->status() === 303 && ($result = $response->header('Location')) !== null) {
                $response = $this->checked($this->requests->createRequest('GET', $this->follow($response, $result)));

                break;
            }

            if ($response->status() === 202) {
                // An older monitor may move itself along.
                $next = $response->header('Location') ?? $response->header('Content-Location');
                $monitor = $next === null ? $monitor : $this->follow($response, $next);

                continue;
            }

            if ($response->status() === 200 && $response->header('Retry-After') !== null) {
                continue;
            }

            break;
        }

        $failure = self::operationFailure($response);

        if ($failure !== null) {
            throw new OperationFailedException($this->redactor->uri($monitor), $failure);
        }

        return $request->result($response);
    }

    /**
     * Sends a status check until it says the work is done, and returns the
     * check's result().
     *
     * For APIs whose asynchronous work isn't a 202 and a monitor, but a job
     * you submit and then look up until a status field says it's finished.
     * There's no standard for this; it's a convention, so the API's own
     * words for "done" and "failed" are passed in. `$done` gets each
     * response and says whether it's finished. `$failed`, if given, reads
     * a failure from one and returns it as a problem, which is thrown as
     * OperationFailedException. Anything else means "still running".
     *
     * The first check is sent straight away. Between checks it waits as
     * long as the response's `Retry-After` says (RFC 9110 section 10.2.3),
     * or `interval`. An error status throws as it would from send().
     *
     * @template TResult
     *
     * @param  Request<TResult>  $request  the status check, sent until it's done
     * @param  Closure(Response): bool  $done
     * @param  (Closure(Response): ?ProblemDetails)|null  $failed
     * @return TResult
     *
     * @throws OperationTimeoutException when it's still running after `timeout` seconds
     * @throws OperationFailedException when `$failed` reads a failure
     */
    public function poll(Request $request, Closure $done, ?Closure $failed = null, float $timeout = 300.0, float $interval = 2.0): mixed
    {
        $started = $this->clock->now();
        $uri = $this->redactor->uri((string) $this->toPsr($request)->getUri());

        while (true) {
            // Built afresh each time, so a check with a body never resends a spent stream.
            $response = $this->checked($this->toPsr($request));
            $failure = $failed === null ? null : $failed($response);

            if ($failure !== null) {
                throw new OperationFailedException($uri, $failure);
            }

            if ($done($response)) {
                return $request->result($response);
            }

            $wait = $response->retryAfter() ?? $interval;
            $waited = Seconds::between($started, $this->clock->now());

            if ($waited + $wait > $timeout) {
                throw new OperationTimeoutException($uri, $waited);
            }

            $this->sleeper->sleep($wait);
        }
    }

    /**
     * A monitor that reports a failed operation with a 200, as its own GET
     * succeeded: `"status": "failed"` beside an RFC 9457 problem object in
     * `error`, as api-toolbox's monitor does. A result that merely has a
     * status field is left alone: without a problem in `error` it is not a
     * report of failure.
     */
    private static function operationFailure(Response $response): ?ProblemDetails
    {
        if ($response->status() !== 200 || ! str_contains((string) $response->contentType(), 'json')) {
            return null;
        }

        try {
            $body = $response->json();
        } catch (InvalidResponseException) {
            return null;
        }

        $status = $body['status'] ?? null;
        $error = $body['error'] ?? null;

        if (! is_string($status) || ! in_array(strtolower($status), ['failed', 'canceled', 'cancelled'], true) || ! is_array($error)) {
            return null;
        }

        if (! isset($error['type']) && ! isset($error['title'])) {
            return null;
        }

        /** @var array<string, mixed> $error */
        return ProblemDetails::fromArray($error, (string) $response->request()->getUri());
    }

    /**
     * Every item across every page, as the pagination strategy walks them.
     *
     * The request's result() reads one page into its items; the strategy
     * decides what the next page's request is. Each page is sent like any
     * other request, and an error status on any page throws.
     *
     * @template TItem
     *
     * @param  Request<iterable<TItem>>  $request
     * @return Paginator<TItem>
     */
    public function paginate(Request $request, Pagination $pagination, ?int $maxPages = null): Paginator
    {
        return new Paginator(
            $request,
            $this->toPsr($request),
            $pagination,
            $this->checked(...),
            $maxPages,
        );
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

        if ($request instanceof HasPreferences && ! $request->preferences()->isEmpty()) {
            $headers['Prefer'] = $request->preferences()->header();
        }

        if ($request instanceof Conditional) {
            $headers = [...$headers, ...($request->precondition()->headers ?? [])];
        }

        // One key per send(), kept by every retry of it: the request below is
        // built once and the retry middleware resends this same object.
        if ($request instanceof Idempotent) {
            $headers[$this->idempotencyHeader] = $this->idempotencyKeys->header($request->idempotencyKey() ?? IdempotencyKey::generate());
        }

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
        $copy = clone $this;
        $copy->middleware = [...$this->middleware, ...array_values($middleware)];

        return $copy;
    }

    #[NoDiscard]
    public function withRetry(RetryStrategy $retry): static
    {
        $copy = clone $this;
        $copy->retry = $this->keyedBy($retry);

        return $copy;
    }

    /**
     * The connector's own retry policy has to recognise the header it sends
     * keys in. A custom RetryStrategy is left alone; it reads the request.
     */
    private function keyedBy(RetryStrategy $retry): RetryStrategy
    {
        return $retry instanceof RetryPolicy && $retry->idempotencyHeader !== $this->idempotencyHeader
            ? $retry->withIdempotencyHeader($this->idempotencyHeader)
            : $retry;
    }

    #[NoDiscard]
    public function withHttpClient(ClientInterface $http): static
    {
        $copy = clone $this;
        $copy->http = $http;

        return $copy;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    #[NoDiscard]
    public function withHeaders(array $headers): static
    {
        $copy = clone $this;
        $copy->headers = [...$this->headers, ...$headers];

        return $copy;
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

    /**
     * Sends a request the connector didn't build from a Request, such as a
     * next page or an operation monitor, after checking it's still under the
     * base URI, and throws for an error status.
     */
    private function checked(RequestInterface $psr): Response
    {
        if (! $this->baseUri->allows((string) $psr->getUri())) {
            throw new UnsafeUriException("Refusing to follow the API outside the base URI: [{$this->redactor->uri((string) $psr->getUri())}].");
        }

        if (! $psr->hasHeader('Accept')) {
            $psr = $psr->withHeader('Accept', 'application/json, application/problem+json');
        }

        if (! $psr->hasHeader('User-Agent')) {
            $psr = $psr->withHeader('User-Agent', $this->userAgentString());
        }

        $response = $this->dispatch($psr);

        if ($response->failed()) {
            throw $this->exceptions->map($response, $this->redactor);
        }

        return $response;
    }

    /**
     * A Location from a response, resolved against the request it answered.
     */
    private function follow(Response $response, string $location): string
    {
        return Uri::parse($location, Uri::parse((string) $response->request()->getUri()))?->toString() ?? $location;
    }

    /**
     * @throws TransportException when no response came back
     */
    private function dispatch(RequestInterface $psr): Response
    {
        try {
            $answer = $this->pipeline()->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::from($e, $psr, $this->redactor);
        }

        return new Response($answer, $psr, $this->clock, $this->hydrator);
    }

    private function pipeline(): Pipeline
    {
        // One counter per send, shared by the retry middleware that counts
        // attempts and the instrumentation that reports them.
        $attempts = new Attempts;

        $middleware = [
            new Retry($this->retry, $this->clock, $this->sleeper, $this->telemetry, $attempts),
            ...($this->throttle === null ? [] : [$this->throttle->withTelemetry($this->telemetry)]),
            ...($this->deprecations === null ? [] : [$this->deprecations]),
            ...$this->middleware,
        ];

        if ($this->auth !== null) {
            $middleware[] = new Authenticate($this->auth, $this->telemetry);
        }

        // Last, so it sees the credentials it has to key entries on.
        if ($this->cache !== null) {
            $middleware[] = $this->cache;
        }

        // Per attempt, right before the client: a cache hit isn't an attempt.
        if ($this->telemetry->enabled()) {
            $middleware[] = new Observe($this->telemetry, $attempts);
        }

        if ($this->tracing instanceof OpenTelemetrySpans) {
            $middleware[] = $this->tracing->withRedactor($this->redactor)->withAttempts($attempts);
        } elseif ($this->tracing !== null) {
            $middleware[] = $this->tracing;
        }

        return new Pipeline($this->http, $middleware);
    }

    private function userAgentString(): string
    {
        $toolbox = 'sdk-toolbox/'.self::VERSION.' php/'.PHP_VERSION;

        return $this->userAgent === null ? $toolbox : "{$this->userAgent} {$toolbox}";
    }
}
