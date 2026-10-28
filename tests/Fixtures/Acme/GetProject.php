<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Project>
 */
final readonly class GetProject implements HasQuery, Request
{
    public function __construct(
        private string $id,
        private bool $withMembers = false,
    ) {}

    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/projects/'.rawurlencode($this->id);
    }

    public function query(): array
    {
        return ['include_members' => $this->withMembers ?: null];
    }

    public function result(Response $response): Project
    {
        return Project::fromArray($response->json());
    }
}
