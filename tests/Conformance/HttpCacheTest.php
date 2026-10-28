<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Http\HttpDate;
use JustSteveKing\SdkToolbox\Testing\ArrayCache;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;

/**
 * RFC 9111, as a private cache.
 */
function cached(FakeClient $http, FakeTime $time, ?ArrayCache $cache = null, string $token = 't'): Connector
{
    return new Connector(
        'https://api.example.com',
        auth: new BearerToken($token),
        http: $http,
        clock: $time,
        sleeper: $time,
        cache: $cache ?? new ArrayCache($time),
    );
}

it('serves a fresh response without a request, with its Age (4.2, 5.1)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'max-age=60']));
    $connector = cached($http, $time);

    $connector->response(ping());
    $time->advance(30);
    $second = $connector->response(ping());

    $http->assertSentCount(1);
    expect($second->json())->toBe(['v' => 1])
        ->and($second->header('Age'))->toBe('30');
});

it('revalidates a stale response with its validators, and reuses the body on 304 (4.3)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'max-age=10', 'ETag' => '"v1"', 'Last-Modified' => 'Sun, 15 Nov 2026 09:00:00 GMT']),
        FakeResponse::empty(304, ['Cache-Control' => 'max-age=60', 'ETag' => '"v1"']),
    );
    $connector = cached($http, $time);

    $connector->response(ping());
    $time->advance(11);
    $revalidated = $connector->response(ping());

    $request = $http->last();
    expect($request?->getHeaderLine('If-None-Match'))->toBe('"v1"')
        ->and($request?->getHeaderLine('If-Modified-Since'))->toBe('Sun, 15 Nov 2026 09:00:00 GMT')
        ->and($revalidated->status())->toBe(200)
        ->and($revalidated->json())->toBe(['v' => 1]);

    // The 304's max-age now governs: fresh for another minute.
    $time->advance(30);
    $connector->response(ping());
    $http->assertSentCount(2);
});

it('replaces the stored response when revalidation brings a new one', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['v' => 1], headers: ['ETag' => '"v1"']),
        FakeResponse::json(['v' => 2], headers: ['ETag' => '"v2"']),
        FakeResponse::empty(304),
    );
    $connector = cached($http, $time);

    $connector->response(ping());
    expect($connector->response(ping())->json())->toBe(['v' => 2]);
    expect($connector->response(ping())->json())->toBe(['v' => 2])
        ->and($http->last()?->getHeaderLine('If-None-Match'))->toBe('"v2"');
});

it('always revalidates a response with a validator but no freshness (no heuristics)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['ETag' => '"v1"']), FakeResponse::empty(304));
    $connector = cached($http, $time);

    $connector->response(ping());
    $connector->response(ping());

    $http->assertSentCount(2);
});

it('reads freshness from Expires minus Date (5.3)', function (): void {
    $time = new FakeTime;
    $now = $time->now();
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: [
        'Date' => HttpDate::format($now),
        'Expires' => HttpDate::format($now->modify('+120 seconds')),
    ]));
    $connector = cached($http, $time);

    $connector->response(ping());
    $time->advance(100);
    $connector->response(ping());

    $http->assertSentCount(1);
});

it('treats an invalid Expires as already expired (5.3)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['Expires' => '0', 'ETag' => '"a"']), FakeResponse::empty(304));
    $connector = cached($http, $time);

    $connector->response(ping());
    $connector->response(ping());

    $http->assertSentCount(2);
});

it('revalidates no-cache responses every time (5.2.2.4)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'no-cache, max-age=600', 'ETag' => '"a"']), FakeResponse::empty(304));
    $connector = cached($http, $time);

    $connector->response(ping());
    $connector->response(ping());

    $http->assertSentCount(2);
});

it('stores nothing marked no-store (5.2.2.5)', function (): void {
    $time = new FakeTime;
    $cache = new ArrayCache($time);
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'no-store, max-age=600']), FakeResponse::json(['v' => 2]));

    cached($http, $time, $cache)->response(ping());

    expect($cache->all())->toBe([]);
});

it('never stores a 429 (RFC 6585 4)', function (): void {
    $time = new FakeTime;
    $cache = new ArrayCache($time);

    new Connector('https://api.example.com', http: new FakeClient(FakeResponse::empty(429, ['Cache-Control' => 'max-age=600'])), clock: $time, sleeper: $time, cache: $cache, retry: JustSteveKing\SdkToolbox\Retries\RetryPolicy::none())->response(ping());

    expect($cache->all())->toBe([]);
});

it('uses a stored response only for requests that match its Vary (4.1)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['lang' => 'en'], headers: ['Cache-Control' => 'max-age=600', 'Vary' => 'Accept-Language']),
        FakeResponse::json(['lang' => 'fr'], headers: ['Cache-Control' => 'max-age=600', 'Vary' => 'Accept-Language']),
    );
    $connector = cached($http, $time);

    $connector->withHeaders(['Accept-Language' => 'en'])->response(ping());
    $connector->withHeaders(['Accept-Language' => 'fr'])->response(ping());

    $http->assertSentCount(2);
});

it('never shares a response between credentials', function (): void {
    $time = new FakeTime;
    $cache = new ArrayCache($time);
    $http = new FakeClient(
        FakeResponse::json(['user' => 'ada'], headers: ['Cache-Control' => 'max-age=600']),
        FakeResponse::json(['user' => 'grace'], headers: ['Cache-Control' => 'max-age=600']),
    );

    cached($http, $time, $cache, token: 'ada-token')->response(ping());

    expect(cached($http, $time, $cache, token: 'grace-token')->response(ping())->json())->toBe(['user' => 'grace'])
        ->and(json_encode($cache->all()))->not->toContain('ada-token');
});

it('invalidates a URI after a successful unsafe request (4.4)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'max-age=600']),
        FakeResponse::empty(204),
        FakeResponse::json(['v' => 2], headers: ['Cache-Control' => 'max-age=600']),
    );
    $connector = cached($http, $time);

    $connector->response(ping());
    $connector->response(deleteOf('/ping'));

    expect($connector->response(ping())->json())->toBe(['v' => 2]);
});

it('honours no-store and no-cache on the request (5.2.1)', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(
        FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'max-age=600', 'ETag' => '"a"']),
        FakeResponse::empty(304),
    );
    $connector = cached($http, $time);

    $connector->response(ping());
    $connector->withHeaders(['Cache-Control' => 'no-cache'])->response(ping());

    $http->assertSentCount(2);
    $http->assertSent(fn (RequestInterface $r): bool => $r->getHeaderLine('If-None-Match') === '"a"');
});

it('ignores whatever else is in the store under its key', function (): void {
    $time = new FakeTime;
    $cache = new ArrayCache($time);
    $http = new FakeClient(FakeResponse::json(['v' => 1], headers: ['Cache-Control' => 'max-age=600']), FakeResponse::json(['v' => 2]));
    $connector = cached($http, $time, $cache);

    $connector->response(ping());
    foreach (array_keys($cache->all()) as $key) {
        $cache->set($key, 'O:8:"stdClass":0:{}');
    }

    expect($connector->response(ping())->json())->toBe(['v' => 2]);
});

it('stops the throttle reading rate limits from a cached response (ratelimit draft 7.3)', function (): void {
    $time = new FakeTime;
    $throttle = new JustSteveKing\SdkToolbox\RateLimits\Throttle(clock: $time, sleeper: $time);
    $exhausted = ['RateLimit' => '"default";r=0;t=60'];

    // A response with Age came from a cache somewhere: its quota is old news.
    $fromCache = new FakeClient(FakeResponse::json([], headers: [...$exhausted, 'Age' => '30']), FakeResponse::json([]));
    $connector = new Connector('https://api.example.com', http: $fromCache, clock: $time, sleeper: $time, throttle: $throttle);
    $connector->response(ping());
    $connector->response(ping());
    expect($time->sleeps())->toBe([]);

    // The same headers fresh from the origin are acted on.
    $fresh = new FakeClient(FakeResponse::json([], headers: $exhausted), FakeResponse::json([]));
    $connector = new Connector('https://api.example.com', http: $fresh, clock: $time, sleeper: $time, throttle: $throttle);
    $connector->response(ping());
    $connector->response(ping());
    expect($time->sleeps())->toBe([60.0]);
});
