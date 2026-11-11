<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests\Untyped;

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * A POST, PUT or PATCH with an array as its JSON body, and the whole
 * decoded body back. Built by JsonRequest::post(), put() and patch().
 *
 * @implements Request<array<array-key, mixed>>
 */
final readonly class JsonBodyRequest implements HasBody, Request
{
    use DecodesJson;

    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        private Method $method,
        private string $path,
        private array $body,
    ) {}

    public function method(): Method
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function body(): Body
    {
        return new JsonBody($this->body);
    }
}
