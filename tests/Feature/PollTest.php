<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\OperationFailedException;
use JustSteveKing\SdkToolbox\Errors\OperationTimeoutException;
use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

function polling(FakeClient $http, FakeTime $time): Connector
{
    return new Connector('https://api.example.com/v1', auth: new QueryKey('api_key', 'secret'), http: $http, clock: $time, sleeper: $time);
}

function finished(Response $response): bool
{
    return $response->get('status') === 'Success';
}

it('checks until the status says done, and returns the check\'s result', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['status' => 'Queued']),
        FakeResponse::json(['status' => 'Processing']),
        FakeResponse::json(['status' => 'Success', 'organic_results' => [1, 2]]),
    );

    $result = polling($http, $time)->poll(ping(['id' => 'abc']), finished(...), interval: 3.0);

    expect($result->get('organic_results'))->toBe([1, 2])
        ->and($time->sleeps())->toBe([3.0, 3.0]);
    $http->assertSentCount(3);
});

it('checks straight away, so a job already done costs one request and no wait', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json(['status' => 'Success']));

    polling($http, $time)->poll(ping(), finished(...));

    expect($time->sleeps())->toBe([]);
});

it('waits as long as Retry-After says between checks', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['status' => 'Processing'], headers: ['Retry-After' => '7']),
        FakeResponse::json(['status' => 'Success']),
    );

    polling($http, $time)->poll(ping(), finished(...), interval: 2.0);

    expect($time->sleeps())->toBe([7.0]);
});

it('throws OperationFailedException with the problem the failure reader returns', function (): void {
    $http = new FakeClient(
        FakeResponse::json(['status' => 'Processing']),
        FakeResponse::json(['status' => 'Error', 'error' => 'The search timed out.']),
    );
    $failed = fn (Response $response): ?ProblemDetails => $response->get('status') === 'Error'
        ? new ProblemDetails(title: 'Search failed', detail: (string) $response->get('error'))
        : null;

    try {
        polling($http, new FakeTime)->poll(ping(['id' => 'abc']), finished(...), $failed);
        $this->fail('Expected OperationFailedException');
    } catch (OperationFailedException $e) {
        expect($e->problem->detail)->toBe('The search timed out.')
            ->and($e->monitor)->toBe('https://api.example.com/v1/ping?id=abc');
    }
});

it('gives up at the timeout, naming the check, which never holds the credentials', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(...array_fill(0, 10, FakeResponse::json(['status' => 'Processing'])));

    try {
        polling($http, $time)->poll(ping(['id' => 'abc']), finished(...), timeout: 10.0, interval: 4.0);
        $this->fail('Expected OperationTimeoutException');
    } catch (OperationTimeoutException $e) {
        expect($e->waited)->toBe(8.0)
            ->and($e->monitor)->toBe('https://api.example.com/v1/ping?id=abc')
            ->and($e->getMessage())->not->toContain('secret');
    }
});

it('throws an error status as send() would', function (): void {
    $http = new FakeClient(FakeResponse::problem(404, title: 'Not Found'));

    expect(fn () => polling($http, new FakeTime)->poll(ping(), finished(...)))->toThrow(NotFoundException::class);
});
