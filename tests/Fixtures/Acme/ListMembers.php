<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * One page of members from send(), every member from paginate().
 *
 * @implements Request<list<Member>>
 */
final readonly class ListMembers implements HasQuery, Request
{
    public function __construct(
        private ?Role $role = null,
    ) {}

    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/members';
    }

    public function query(): array
    {
        return ['role' => $this->role];
    }

    public function result(Response $response): array
    {
        return $response->mapList(Member::class, at: 'data');
    }
}
