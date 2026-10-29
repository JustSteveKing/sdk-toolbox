<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\OperationFailedException;
use JustSteveKing\SdkToolbox\Errors\OperationTimeoutException;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;

function awaiting(FakeClient $http, FakeTime $time): Connector
{
    return new Connector('https://api.example.com/v1', http: $http, clock: $time, sleeper: $time);
}

it('polls a 202 operation, follows the 303, and returns the result', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['status' => 'queued'], 202, ['Location' => '/v1/operations/op_1', 'Retry-After' => '3']),
        FakeResponse::json(['status' => 'running'], 202, ['Location' => '/v1/operations/op_1']),
        FakeResponse::empty(303, ['Location' => '/v1/exports/ex_1']),
        FakeResponse::json(['id' => 'ex_1', 'url' => 'https://files.example.com/ex_1.csv']),
    );

    $result = awaiting($http, $time)->await(ping(), interval: 5.0);

    expect($result->json())->toBe(['id' => 'ex_1', 'url' => 'https://files.example.com/ex_1.csv'])
        ->and($time->sleeps())->toBe([3.0, 5.0])
        ->and(array_map(fn (RequestInterface $r): string => $r->getMethod().' '.$r->getUri()->getPath(), $http->sent()))
        ->toBe(['GET /v1/ping', 'GET /v1/operations/op_1', 'GET /v1/operations/op_1', 'GET /v1/exports/ex_1']);
});

it('takes a 200 from the monitor as the result itself', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(202, ['Location' => 'operations/op_1']), FakeResponse::json(['done' => true]));

    expect(awaiting($http, $time)->await(ping())->json())->toBe(['done' => true]);
});

it('returns a synchronous answer as it is', function (): void {
    $http = new FakeClient(FakeResponse::json(['now' => true]));

    expect(awaiting($http, new FakeTime)->await(ping())->json())->toBe(['now' => true]);
    $http->assertSentCount(1);
});

it('gives up after the timeout, keeping the monitor to look at later', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(...array_fill(0, 10, FakeResponse::empty(202, ['Location' => '/v1/operations/op_1', 'Retry-After' => '30'])));

    try {
        awaiting($http, $time)->await(ping(), timeout: 100.0);
        $this->fail('Expected a timeout');
    } catch (OperationTimeoutException $e) {
        expect($e->monitor)->toBe('https://api.example.com/v1/operations/op_1')
            ->and($e->waited)->toBe(90.0);
    }
});

it('refuses a monitor outside the base URI', function (): void {
    $http = new FakeClient(FakeResponse::empty(202, ['Location' => 'https://evil.example/op']));

    expect(fn () => awaiting($http, new FakeTime)->await(ping()))->toThrow(UnsafeUriException::class);
    $http->assertSentCount(1);
});

it('throws when the operation fails', function (): void {
    $http = new FakeClient(FakeResponse::empty(202, ['Location' => '/v1/operations/op_1']), FakeResponse::problem(500, title: 'Export failed'), FakeResponse::problem(500, title: 'Export failed'), FakeResponse::problem(500, title: 'Export failed'));

    expect(fn () => awaiting($http, new FakeTime)->await(ping()))->toThrow(ServerException::class, 'Export failed');
});

it('keeps polling a monitor that answers 200 with Retry-After, as api-toolbox\'s does', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['id' => 'op_1', 'status' => 'pending'], 202, ['Location' => '/v1/operations/op_1', 'Retry-After' => '2']),
        FakeResponse::json(['id' => 'op_1', 'status' => 'running'], 200, ['Retry-After' => '2']),
        FakeResponse::json(['id' => 'op_1', 'status' => 'running'], 200, ['Retry-After' => '4']),
        FakeResponse::empty(303, ['Location' => '/v1/exports/ex_1']),
        FakeResponse::json(['id' => 'ex_1']),
    );

    $result = awaiting($http, $time)->await(ping());

    expect($result->json())->toBe(['id' => 'ex_1'])
        ->and($time->sleeps())->toBe([2.0, 2.0, 4.0]);
    $http->assertSentTo('GET', '/v1/exports/ex_1');
});

it('throws OperationFailedException with the problem a monitor reports', function (): void {
    $http = new FakeClient(
        FakeResponse::json(['id' => 'op_1', 'status' => 'pending'], 202, ['Location' => '/v1/operations/op_1']),
        FakeResponse::json(['id' => 'op_1', 'status' => 'failed', 'error' => ['type' => 'https://apiguide.dev/errors/internal-server-error', 'title' => 'Internal Server Error', 'status' => 500, 'detail' => 'The export could not be written.']]),
    );

    try {
        awaiting($http, new FakeTime)->await(ping());
        $this->fail('Expected OperationFailedException');
    } catch (OperationFailedException $e) {
        expect($e->monitor)->toBe('https://api.example.com/v1/operations/op_1')
            ->and($e->problem->status)->toBe(500)
            ->and($e->getMessage())->toContain('The export could not be written.');
    }
});

it('returns a result that only happens to have a failed status', function (): void {
    $http = new FakeClient(
        FakeResponse::empty(202, ['Location' => '/v1/operations/op_1']),
        FakeResponse::json(['id' => 'payment_1', 'status' => 'failed', 'error' => 'card_declined']),
    );

    expect(awaiting($http, new FakeTime)->await(ping())->json())->toBe(['id' => 'payment_1', 'status' => 'failed', 'error' => 'card_declined']);
});
