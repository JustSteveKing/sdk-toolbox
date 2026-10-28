<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\RateLimitExceededException;
use JustSteveKing\SdkToolbox\RateLimits\Psr16RateLimitStore;
use JustSteveKing\SdkToolbox\RateLimits\Throttle;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\SimpleCache\CacheInterface;

function throttled(FakeClient $http, FakeTime $time, ?Throttle $throttle = null): Connector
{
    return new Connector(
        'https://api.example.com',
        http: $http,
        retry: RetryPolicy::none(),
        throttle: $throttle ?? new Throttle(maxWait: 60.0, clock: $time, sleeper: $time),
        clock: $time,
        sleeper: $time,
    );
}

it('waits for a spent quota to reset before sending again', function (): void {
    $http = new FakeClient(
        FakeResponse::empty(200, ['RateLimit' => '"default";r=0;t=12']),
        FakeResponse::empty(200, ['RateLimit' => '"default";r=99;t=60']),
    );
    $time = new FakeTime;
    $connector = throttled($http, $time);

    $connector->response(ping());
    expect($time->sleeps())->toBe([]);

    $connector->response(ping());
    expect($time->sleeps())->toBe([12.0]);
});

it('does not wait while quota is left', function (): void {
    $http = new FakeClient(
        FakeResponse::empty(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset' => '30']),
        FakeResponse::empty(200),
    );
    $time = new FakeTime;
    $connector = throttled($http, $time);

    $connector->response(ping());
    $connector->response(ping());

    expect($time->sleeps())->toBe([]);
});

it('refuses to send rather than wait past its limit', function (): void {
    $http = new FakeClient(FakeResponse::empty(200, ['RateLimit' => '"default";r=0;t=3600']));
    $time = new FakeTime;
    $connector = throttled($http, $time);

    $connector->response(ping());

    expect(fn () => $connector->response(ping()))->toThrow(RateLimitExceededException::class);
    $http->assertSentCount(1);
});

it('lets Retry-After on a 429 win over the RateLimit fields (draft section 7)', function (): void {
    $http = new FakeClient(
        FakeResponse::empty(429, ['Retry-After' => '5', 'RateLimit' => '"default";r=0;t=50']),
        FakeResponse::empty(200),
    );
    $time = new FakeTime;
    $connector = throttled($http, $time);

    $connector->response(ping());
    $connector->response(ping());

    expect($time->sleeps())->toBe([5.0]);
});

it('keeps hosts apart', function (): void {
    $http = new FakeClient(FakeResponse::empty(200, ['RateLimit' => '"default";r=0;t=30']), FakeResponse::empty(200));
    $time = new FakeTime;
    $throttle = new Throttle(clock: $time, sleeper: $time);

    throttled($http, $time, $throttle)->response(ping());
    new Connector('https://other.example.com', http: $http, throttle: $throttle, clock: $time, sleeper: $time)->response(ping());

    expect($time->sleeps())->toBe([]);
});

it('shares state through a PSR-16 cache', function (): void {
    $cache = new class implements CacheInterface
    {
        /** @var array<string, mixed> */
        public array $items = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->items[$key]);
        }
    };
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(200, ['RateLimit' => '"default";r=0;t=8']), FakeResponse::empty(200));

    // Two connectors, as two processes would have, sharing one cache.
    throttled($http, $time, new Throttle(new Psr16RateLimitStore($cache), clock: $time, sleeper: $time))->response(ping());
    expect($cache->items)->toHaveCount(1);

    throttled($http, $time, new Throttle(new Psr16RateLimitStore($cache), clock: $time, sleeper: $time))->response(ping());
    expect($time->sleeps())->toBe([8.0]);
});
