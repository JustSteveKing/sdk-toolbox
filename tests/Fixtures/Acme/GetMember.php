<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Member>
 */
final readonly class GetMember implements Request
{
    public function __construct(
        private string $id,
    ) {}

    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/members/'.rawurlencode($this->id);
    }

    public function result(Response $response): Member
    {
        return $response->map(Member::class, at: 'data');
    }
}
