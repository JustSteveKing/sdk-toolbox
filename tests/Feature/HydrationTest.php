<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\HydrationException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Hydration\Hydrator;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Acme;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\GetMember;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Role;

function member(string $id = 'm_1', string $role = 'owner'): array
{
    return ['id' => $id, 'display_name' => 'Ada', 'role' => $role, 'joined_at' => '2026-01-05T09:00:00+00:00'];
}

it('maps snake_case JSON onto a typed object', function (): void {
    $http = new FakeClient(FakeResponse::json(['data' => member()]));

    $member = new Acme('t', $http)->send(new GetMember('m_1'));

    expect($member)->toEqual(new Member('m_1', 'Ada', Role::Owner, new DateTimeImmutable('2026-01-05T09:00:00+00:00')));
});

it('ignores fields the model does not know, so a new API field breaks nothing', function (): void {
    $http = new FakeClient(FakeResponse::json(['data' => [...member(), 'shiny_new_field' => true]]));

    expect(new Acme('t', $http)->send(new GetMember('m_1'))->displayName)->toBe('Ada');
});

it('maps a list', function (): void {
    $http = new FakeClient(FakeResponse::json(['data' => [member('m_1'), member('m_2', 'member')]]));

    $members = new Acme('t', $http)->send(new ListMembers);

    expect($members)->toHaveCount(2)
        ->and($members[1]->role)->toBe(Role::Member);
});

it('says where a response does not fit, in the API\'s own key', function (): void {
    $http = new FakeClient(FakeResponse::json(['data' => [...member(), 'display_name' => 42]]));

    try {
        new Acme('t', $http)->send(new GetMember('m_1'));
        $this->fail('Expected a HydrationException');
    } catch (HydrationException $e) {
        expect($e->type)->toBe(Member::class)
            ->and($e->errors)->toBe(['display_name: Value 42 is not a valid string.'])
            ->and($e->getMessage())->toBe("The response doesn't fit ".Member::class.': display_name: Value 42 is not a valid string.');
    }
});

it('takes any hydrator', function (): void {
    $hydrator = new class implements Hydrator
    {
        public function hydrate(string $class, mixed $data): object
        {
            return new $class(...(array) $data);
        }

        public function hydrateList(string $class, mixed $data): array
        {
            return [];
        }
    };
    $connector = new Connector('https://api.example.com', http: new FakeClient(FakeResponse::json(['message' => 'pong'])), hydrator: $hydrator);

    expect($connector->response(ping())->map(Pong::class))->toEqual(new Pong('pong'));
});

it('says what to do when there is no hydrator', function (): void {
    $response = new JustSteveKing\SdkToolbox\Http\Response(FakeResponse::json([]), new Nyholm\Psr7\Request('GET', 'https://x'));

    expect(fn () => $response->map(Pong::class))->toThrow(InvalidRequestException::class, 'Install cuyz/valinor');
});

final readonly class Pong
{
    public function __construct(public string $message) {}
}
