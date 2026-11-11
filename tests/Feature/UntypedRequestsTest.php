<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidResponseException;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Requests\Untyped\JsonRequest;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

function untyped(FakeClient $http, ?RetryPolicy $retry = null, ?FakeTime $time = null): Connector
{
    $time ??= new FakeTime;

    return new Connector('https://api.acme.test/v2', http: $http, retry: $retry ?? RetryPolicy::none(), clock: $time, sleeper: $time);
}

it('gets the whole body, including fields no class knows', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Website', 'added_last_week' => true]));

    $project = untyped($http)->send(JsonRequest::get('/projects/prj_1', ['expand' => 'owner']));

    expect($project)->toBe(['id' => 'prj_1', 'name' => 'Website', 'added_last_week' => true]);

    $http->assertSentTo('GET', '/v2/projects/prj_1');
    expect($http->last()->getUri()->getQuery())->toBe('expand=owner');
});

it('sends an array as the JSON body of a POST, PUT and PATCH', function (string $method, Closure $request): void {
    $http = new FakeClient(FakeResponse::json(['ok' => true]));

    untyped($http)->send($request());

    expect($http->last()->getMethod())->toBe($method)
        ->and($http->last()->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $http->last()->getBody(), true))->toBe(['name' => 'Website']);
})->with([
    'post' => ['POST', fn () => JsonRequest::post('/projects', ['name' => 'Website'])],
    'put' => ['PUT', fn () => JsonRequest::put('/projects/prj_1', ['name' => 'Website'])],
    'patch' => ['PATCH', fn () => JsonRequest::patch('/projects/prj_1', ['name' => 'Website'])],
]);

it('sends a DELETE with no body, and gives an empty array for an empty answer', function (): void {
    $http = new FakeClient(FakeResponse::empty());

    expect(untyped($http)->send(JsonRequest::delete('/projects/prj_1')))->toBe([]);

    expect($http->last()->getMethod())->toBe('DELETE')
        ->and((string) $http->last()->getBody())->toBe('')
        ->and($http->last()->hasHeader('Content-Type'))->toBeFalse();
});

it('carries no idempotency key unless given one, and is then not retried after a 500', function (): void {
    $http = new FakeClient(FakeResponse::problem(500, title: 'Oops'));

    expect(fn () => untyped($http, new RetryPolicy(jitter: 0.0))->send(JsonRequest::post('/charges', ['amount' => 100])))
        ->toThrow(JustSteveKing\SdkToolbox\Errors\ServerException::class);

    expect($http->last()->hasHeader('Idempotency-Key'))->toBeFalse();
    $http->assertSentCount(1);
});

it('retries a POST given a key with that same key every time', function (): void {
    $http = new FakeClient(FakeResponse::problem(500, title: 'Oops'), FakeResponse::json(['id' => 'ch_1']));

    $charge = untyped($http, new RetryPolicy(jitter: 0.0))->send(JsonRequest::post('/charges', ['amount' => 100], idempotencyKey: 'charge-42'));

    expect($charge)->toBe(['id' => 'ch_1']);

    [$first, $second] = $http->sent();

    expect($first->getHeaderLine('Idempotency-Key'))->toContain('charge-42')
        ->and($second->getHeaderLine('Idempotency-Key'))->toBe($first->getHeaderLine('Idempotency-Key'));
});

it('raises the same exceptions as a typed request', function (): void {
    $http = new FakeClient(FakeResponse::problem(404, title: 'Not Found'));

    expect(fn () => untyped($http)->send(JsonRequest::get('/projects/missing')))->toThrow(NotFoundException::class);
});

it('refuses a body that is not JSON', function (): void {
    $http = new FakeClient(FakeResponse::text('<html>hello</html>'));

    expect(fn () => untyped($http)->send(JsonRequest::get('/projects')))->toThrow(InvalidResponseException::class);
});
