<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\ClientCredentials;
use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Deprecation\Deprecations;
use JustSteveKing\SdkToolbox\Events\CacheHit;
use JustSteveKing\SdkToolbox\Events\CredentialsRefreshed;
use JustSteveKing\SdkToolbox\Events\RateLimitWaiting;
use JustSteveKing\SdkToolbox\Events\RequestFailed;
use JustSteveKing\SdkToolbox\Events\RequestSending;
use JustSteveKing\SdkToolbox\Events\ResponseReceived;
use JustSteveKing\SdkToolbox\Events\RetryScheduled;
use JustSteveKing\SdkToolbox\RateLimits\Throttle;
use JustSteveKing\SdkToolbox\Testing\ArrayCache;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

it('logs each attempt, redacted', function (): void {
    $logger = new CollectingLogger;
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(503), FakeResponse::json(['ok' => true]));
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', 'top-secret'), http: $http, clock: $time, sleeper: $time, logger: $logger);

    $connector->response(ping(['q' => 'coffee']));

    $messages = $logger->messages();
    expect($messages)->toHaveCount(5)
        ->and($messages[0])->toBe('debug: Sending GET https://api.example.com/ping?q=coffee&api_key=%5Bredacted%5D')
        ->and($messages[1])->toMatch('/^warning: GET https:\/\/api\.example\.com\/ping\?q=coffee&api_key=%5Bredacted%5D returned 503 in \d+ ms$/')
        ->and($messages[2])->toMatch('/^info: Retrying GET .* in \d\.\d\ds after 503 \(attempt 2\)$/')
        ->and($messages[3])->toBe('debug: Sending GET https://api.example.com/ping?q=coffee&api_key=%5Bredacted%5D (attempt 2)')
        ->and($messages[4])->toMatch('/^debug: .* returned 200 in \d+ ms$/')
        ->and(json_encode($logger->records))->not->toContain('top-secret');
});

it('dispatches the same events through PSR-14', function (): void {
    $events = new CollectingDispatcher;
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::networkError('Timed out for https://api.example.com/ping?api_key=top-secret'), FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', 'top-secret'), http: $http, clock: $time, sleeper: $time, events: $events);

    $connector->response(ping());

    expect(array_map(fn (object $e): string => $e::class, $events->events))->toBe([
        RequestSending::class, RequestFailed::class, RetryScheduled::class, RequestSending::class, ResponseReceived::class,
    ])
        ->and($events->of(RequestFailed::class)[0]->reason)->toBe('Timed out for https://api.example.com/ping?api_key=%5Bredacted%5D')
        ->and($events->of(RetryScheduled::class)[0]->status)->toBeNull()
        ->and(array_map(fn (RequestSending $e): int => $e->attempt, $events->of(RequestSending::class)))->toBe([1, 2])
        ->and(serialize(array_map(fn (object $e): array => (array) $e, $events->of(RequestSending::class))))->not->toContain('top-secret');
});

it('reports the throttle waiting', function (): void {
    $events = new CollectingDispatcher;
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(200, ['RateLimit' => '"default";r=0;t=9']), FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, events: $events, throttle: new Throttle(clock: $time, sleeper: $time));

    $connector->response(ping());
    $connector->response(ping());

    expect($events->of(RateLimitWaiting::class))->toEqual([new RateLimitWaiting('https://api.example.com', 9.0)]);
});

it('reports credentials being renewed', function (): void {
    $events = new CollectingDispatcher;
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(401), FakeResponse::empty(200));
    $connector = new Connector('https://api.example.com', auth: new ClientCredentials(oauthClient(new FakeClient(token('a'), token('b')), $time)), http: $http, clock: $time, sleeper: $time, events: $events);

    $connector->response(ping());

    expect($events->of(CredentialsRefreshed::class))->toHaveCount(1);
});

it('reports cache hits, and sends nothing for a fresh one', function (): void {
    $events = new CollectingDispatcher;
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json([], headers: ['Cache-Control' => 'max-age=10', 'ETag' => '"a"']), FakeResponse::empty(304));
    $connector = new Connector('https://api.example.com', http: $http, clock: $time, sleeper: $time, events: $events, cache: new ArrayCache($time));

    $connector->response(ping());
    $connector->response(ping());
    $time->advance(20);
    $connector->response(ping());

    expect(array_map(fn (CacheHit $e): bool => $e->revalidated, $events->of(CacheHit::class)))->toBe([false, true])
        ->and($events->of(RequestSending::class))->toHaveCount(2);
});

it('reports deprecations with or without a handler', function (): void {
    $logger = new CollectingLogger;
    $http = new FakeClient(...array_fill(0, 2, FakeResponse::empty(200, ['Deprecation' => '@1688169599'])));
    $connector = new Connector('https://api.example.com', http: $http, logger: $logger, deprecations: new Deprecations);

    $connector->response(ping());
    $connector->response(ping());

    expect(array_values(array_filter($logger->messages(), fn (string $m): bool => str_starts_with($m, 'warning'))))
        ->toBe(['warning: GET https://api.example.com/ping is deprecated as of 2023-06-30.']);
});

it('keeps instrumentation out of the pipeline when nobody is listening', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));

    expect(new Connector('https://api.example.com', http: $http)->response(ping())->status())->toBe(200);
});
