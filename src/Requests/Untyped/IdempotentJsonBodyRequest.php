<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests\Untyped;

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Idempotent;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * A POST with a JSON body and the idempotency key it was given, so the
 * retry policy may send it again after a failure the API might have
 * acted on, with the same key every time. Built by JsonRequest::post()
 * when a key is passed.
 *
 * @implements Request<array<array-key, mixed>>
 */
final readonly class IdempotentJsonBodyRequest implements HasBody, Idempotent, Request
{
    use DecodesJson;

    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        private string $path,
        private array $body,
        private string $idempotencyKey,
    ) {}

    public function method(): Method
    {
        return Method::Post;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function body(): Body
    {
        return new JsonBody($this->body);
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
