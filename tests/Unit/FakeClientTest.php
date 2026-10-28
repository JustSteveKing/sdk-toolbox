<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use PHPUnit\Framework\AssertionFailedError;

it('asserts on method and path, JSON subsets and order', function (): void {
    $http = new FakeClient(FakeResponse::empty(201), FakeResponse::empty(200), FakeResponse::empty(204));
    $connector = new Connector('https://api.example.com', http: $http);

    $connector->send(post(new JsonBody(['amount' => 500, 'meta' => ['ref' => 'x', 'n' => 1]])));
    $connector->response(ping());
    $connector->response(deleteOf('/charges'));

    $http->assertSentTo('POST', '/charges');
    $http->assertSentJson(['meta' => ['ref' => 'x']]);
    $http->assertSentInOrder(['POST /charges', 'DELETE /charges']);

    expect(fn () => $http->assertSentJson(['amount' => 501]))->toThrow(AssertionFailedError::class)
        ->and(fn () => $http->assertSentInOrder(['DELETE /charges', 'POST /charges']))->toThrow(AssertionFailedError::class)
        ->and(fn () => $http->assertSentTo('PUT', '/charges'))->toThrow(AssertionFailedError::class);
});
