<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Project>
 */
final readonly class CreateProject implements HasBody, Request
{
    public function __construct(
        private string $name,
    ) {}

    public function method(): Method
    {
        return Method::Post;
    }

    public function path(): string
    {
        return '/projects';
    }

    public function body(): Body
    {
        return new JsonBody(['name' => $this->name]);
    }

    public function result(Response $response): Project
    {
        return Project::fromArray($response->json());
    }
}
