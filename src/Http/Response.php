<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use JsonException;
use JustSteveKing\SdkToolbox\Errors\HydrationException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidResponseException;
use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Hydration\Hydrator;
use JustSteveKing\SdkToolbox\RateLimits\RateLimit;
use JustSteveKing\SdkToolbox\RateLimits\RateLimits;
use JustSteveKing\SdkToolbox\Retries\RetryAfter;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-7 response with typed readers, and the request that produced it.
 */
final readonly class Response
{
    public function __construct(
        private ResponseInterface $response,
        private RequestInterface $request,
        private ClockInterface $clock = new SystemClock,
        private ?Hydrator $hydrator = null,
    ) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function failed(): bool
    {
        return $this->status() >= 400;
    }

    /**
     * Every value of a header, joined with commas, or null when it's absent.
     */
    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name) ? $this->response->getHeaderLine($name) : null;
    }

    /**
     * The media type, lowercased, without parameters: `application/json`.
     */
    public function contentType(): ?string
    {
        $header = $this->header('Content-Type');

        if ($header === null) {
            return null;
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The body as a decoded JSON object or array.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidResponseException when the body isn't a JSON object or array
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidResponseException::notJson($this, $e);
        }

        if (! is_array($decoded)) {
            throw InvalidResponseException::notJson($this);
        }

        return $decoded;
    }

    /**
     * A value from the JSON body by dotted path, such as `meta.next_cursor`,
     * or null when it isn't there.
     */
    public function get(string $path): mixed
    {
        return JsonPath::get($this->json(), $path);
    }

    /**
     * The JSON body, or the part of it at a dotted path, as a typed object.
     *
     *     $response->map(Project::class);
     *     $response->map(Project::class, at: 'data');
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws HydrationException when the data doesn't fit the class
     */
    public function map(string $class, ?string $at = null): object
    {
        return $this->hydrator()->hydrate($class, JsonPath::get($this->json(), $at));
    }

    /**
     * The JSON body, or the part of it at a dotted path, as a list of typed
     * objects.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     *
     * @throws HydrationException when the data isn't a list of the class
     */
    public function mapList(string $class, ?string $at = null): array
    {
        return $this->hydrator()->hydrateList($class, JsonPath::get($this->json(), $at));
    }

    /**
     * Whether the body is an RFC 9457 problem details object.
     */
    public function isProblem(): bool
    {
        return $this->contentType() === 'application/problem+json';
    }

    /**
     * The RFC 9457 problem details in the body, if there are any.
     */
    public function problem(): ?ProblemDetails
    {
        return ProblemDetails::fromResponse($this);
    }

    /**
     * The most restrictive rate-limit policy the response reported, if any.
     */
    public function rateLimit(): ?RateLimit
    {
        return RateLimits::mostRestrictive($this->response, $this->clock);
    }

    /**
     * Every rate-limit policy the response reported.
     *
     * @return list<RateLimit>
     */
    public function rateLimits(): array
    {
        return RateLimits::read($this->response, $this->clock);
    }

    /**
     * Seconds the server asked the client to wait, from `Retry-After`.
     */
    public function retryAfter(): ?float
    {
        return RetryAfter::from($this->response, $this->clock);
    }

    private function hydrator(): Hydrator
    {
        return $this->hydrator ?? throw new InvalidRequestException(
            'No hydrator to map the response with. Install cuyz/valinor, or give the connector a Hydrator.',
        );
    }

    public function psr(): ResponseInterface
    {
        return $this->response;
    }

    public function request(): RequestInterface
    {
        return $this->request;
    }
}
