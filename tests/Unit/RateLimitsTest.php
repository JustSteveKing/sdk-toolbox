<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\RateLimits\RateLimit;
use JustSteveKing\SdkToolbox\RateLimits\RateLimits;
use JustSteveKing\SdkToolbox\RateLimits\RateLimitSource;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

it('reads the IETF fields and joins the policy by name', function (): void {
    $response = FakeResponse::empty(200, [
        'RateLimit' => '"default";r=0;t=30, "daily";r=900;t=3600',
        'RateLimit-Policy' => '"default";q=100;w=60, "daily";q=1000;w=86400',
    ]);

    expect(RateLimits::read($response, new FakeTime))->toEqual([
        new RateLimit('default', 0, 100, 30.0, 60),
        new RateLimit('daily', 900, 1000, 3600.0, 86400),
    ])->and(RateLimits::mostRestrictive($response, new FakeTime)?->policy)->toBe('default');
});

it('ignores a malformed RateLimit field rather than guessing (draft section 7)', function (): void {
    expect(RateLimits::read(FakeResponse::empty(200, ['RateLimit' => 'r=10, t=']), new FakeTime))->toBe([]);
});

it('skips a policy without a remaining quota', function (): void {
    expect(RateLimits::read(FakeResponse::empty(200, ['RateLimit' => '"default";t=10']), new FakeTime))->toBe([]);
});

it('reads the X-RateLimit convention with reset as seconds', function (): void {
    $limit = RateLimits::mostRestrictive(FakeResponse::empty(200, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '42']), new FakeTime);

    expect($limit)->toEqual(new RateLimit('default', 0, 60, 42.0, source: RateLimitSource::Legacy));
});

it('reads a reset that is a Unix timestamp, as GitHub sends it', function (): void {
    $time = new FakeTime('2026-11-16 09:00:00 UTC');
    $reset = $time->now()->getTimestamp() + 900;

    $limit = RateLimits::mostRestrictive(FakeResponse::empty(403, [
        'x-ratelimit-limit' => '5000',
        'x-ratelimit-remaining' => '0',
        'x-ratelimit-reset' => (string) $reset,
        'x-ratelimit-resource' => 'core',
    ]), $time);

    expect($limit?->resetIn)->toBe(900.0)
        ->and($limit?->policy)->toBe('core')
        ->and($limit?->exhausted())->toBeTrue();
});

it('reads a reset in epoch milliseconds', function (): void {
    $time = new FakeTime('2026-11-16 09:00:00 UTC');

    $limit = RateLimits::mostRestrictive(FakeResponse::empty(200, ['RateLimit-Remaining' => '3', 'RateLimit-Reset' => (string) (($time->now()->getTimestamp() + 5) * 1000)]), $time);

    expect($limit?->resetIn)->toBe(5.0);
});

it('reads nothing from a response without rate-limit headers', function (): void {
    expect(RateLimits::mostRestrictive(FakeResponse::empty(200), new FakeTime))->toBeNull();
});
