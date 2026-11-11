<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests\Untyped;

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * A request with no typed result: arrays in, the whole decoded body out.
 *
 * Typed requests and result classes always lag an API a little: a field
 * the API added last week isn't on the class yet, and an endpoint nobody
 * wrapped can't be called at all. These reach any endpoint and every
 * field the day the API ships them, written the way the API's own docs
 * write the request, and they go through everything a typed request does:
 * retries, Retry-After, the throttle, the cache, the exception mapper.
 * Expose them from an SDK however suits it, as a method on the connector
 * or a resource:
 *
 *     $email = $sdk->send(JsonRequest::get('/emails/'.rawurlencode($id)));
 *     $sent  = $sdk->send(JsonRequest::post('/emails', $email, idempotencyKey: $key));
 *
 * Only a POST given an idempotency key carries one, and so may be retried
 * after a failure the API might have acted on. A key is a promise the API
 * has to keep, so it's never generated here: give one only to the
 * endpoints the API documents as idempotent.
 *
 * @implements Request<array<array-key, mixed>>
 */
final readonly class JsonRequest implements HasQuery, Request
{
    use DecodesJson;

    /**
     * @param  array<string, mixed>  $query
     */
    private function __construct(
        private Method $method,
        private string $path,
        private array $query,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function get(string $path, array $query = []): self
    {
        return new self(Method::Get, $path, $query);
    }

    /**
     * A DELETE, with no body.
     *
     * @param  array<string, mixed>  $query
     */
    public static function delete(string $path, array $query = []): self
    {
        return new self(Method::Delete, $path, $query);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function post(string $path, array $body = [], ?string $idempotencyKey = null): JsonBodyRequest|IdempotentJsonBodyRequest
    {
        return $idempotencyKey === null
            ? new JsonBodyRequest(Method::Post, $path, $body)
            : new IdempotentJsonBodyRequest($path, $body, $idempotencyKey);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function put(string $path, array $body = []): JsonBodyRequest
    {
        return new JsonBodyRequest(Method::Put, $path, $body);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function patch(string $path, array $body = []): JsonBodyRequest
    {
        return new JsonBodyRequest(Method::Patch, $path, $body);
    }

    public function method(): Method
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(): array
    {
        return $this->query;
    }
}
