<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeNetworkException;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

function decide(RetryPolicy $policy, RequestInterface $request, ?int $status, int $attempt = 1, float $elapsed = 0.0, array $headers = []): ?float
{
    $response = $status === null ? null : FakeResponse::empty($status, $headers);
    $error = $status === null ? new FakeNetworkException($request) : null;

    return $policy->delay($request, $response, $error, $attempt, $elapsed, new FakeTime);
}

function steady(): RetryPolicy
{
    return new RetryPolicy(jitter: 0.0);
}

it('recognises a key in the header the policy is told about', function (): void {
    $policy = steady()->withIdempotencyHeader('X-Idempotency-Key');

    expect(decide($policy, new Request('POST', '/', ['X-Idempotency-Key' => 'k']), 502))->toBe(0.5)
        ->and(decide($policy, new Request('POST', '/', ['Idempotency-Key' => '"k"']), 502))->toBeNull()
        ->and(decide(steady(), new Request('POST', '/', ['X-Idempotency-Key' => 'k']), 502))->toBeNull();
});

it('retries what the server did not process, for any method', function (int $status): void {
    expect(decide(steady(), new Request('POST', '/'), $status))->toBe(0.5);
})->with([408, 425, 429, 503]);

it('retries what may have landed only when repeating it is safe', function (int $status): void {
    expect(decide(steady(), new Request('GET', '/'), $status))->toBe(0.5)
        ->and(decide(steady(), new Request('PUT', '/'), $status))->toBe(0.5)
        ->and(decide(steady(), new Request('POST', '/'), $status))->toBeNull()
        ->and(decide(steady(), new Request('POST', '/', ['Idempotency-Key' => '"k"']), $status))->toBe(0.5);
})->with([500, 502, 504]);

it('retries a network failure only when repeating it is safe', function (): void {
    expect(decide(steady(), new Request('GET', '/'), null))->toBe(0.5)
        ->and(decide(steady(), new Request('PATCH', '/'), null))->toBeNull()
        ->and(decide(steady(), new Request('PATCH', '/', ['Idempotency-Key' => '"k"']), null))->toBe(0.5);
});

it('does not retry a client error the PSR-18 client raised itself', function (): void {
    $error = new class('Bad request') extends RuntimeException implements ClientExceptionInterface {};

    expect(steady()->delay(new Request('GET', '/'), null, $error, 1, 0.0, new FakeTime))->toBeNull();
});

it('retries 409 only for a keyed request, which the draft uses for "still in progress"', function (): void {
    expect(decide(steady(), new Request('POST', '/', ['Idempotency-Key' => '"k"']), 409))->toBe(0.5)
        ->and(decide(steady(), new Request('GET', '/'), 409))->toBeNull();
});

it('never retries errors that will not change', function (int $status): void {
    expect(decide(steady(), new Request('GET', '/'), $status))->toBeNull();
})->with([400, 401, 403, 404, 410, 422, 501]);

it('backs off exponentially up to the cap', function (): void {
    $policy = new RetryPolicy(maxAttempts: 10, baseDelay: 1.0, maxDelay: 5.0, jitter: 0.0);

    expect(array_map(fn (int $n): ?float => decide($policy, new Request('GET', '/'), 503, $n), [1, 2, 3, 4]))->toBe([1.0, 2.0, 4.0, 5.0]);
});

it('keeps jittered waits under the ceiling', function (): void {
    $policy = new RetryPolicy(baseDelay: 2.0, random: new Randomizer(new Mt19937(7)));

    foreach (range(1, 50) as $ignored) {
        expect(decide($policy, new Request('GET', '/'), 503))->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(2.0);
    }
});

it('waits as long as Retry-After says, instead of backing off', function (): void {
    expect(decide(steady(), new Request('GET', '/'), 429, headers: ['Retry-After' => '7']))->toBe(7.0);
});

it('gives up rather than wait for a Retry-After past the limit', function (): void {
    expect(decide(new RetryPolicy(maxRetryAfter: 30.0), new Request('GET', '/'), 429, headers: ['Retry-After' => '120']))->toBeNull();
});

it('stops at the attempt limit', function (): void {
    expect(decide(steady(), new Request('GET', '/'), 503, attempt: 3))->toBeNull()
        ->and(decide(RetryPolicy::none(), new Request('GET', '/'), 503))->toBeNull();
});

it('stops when the next wait would pass the deadline', function (): void {
    $policy = steady()->withDeadline(10.0);

    expect(decide($policy, new Request('GET', '/'), 503, elapsed: 9.0))->toBe(0.5)
        ->and(decide($policy, new Request('GET', '/'), 503, elapsed: 9.8))->toBeNull()
        ->and(decide($policy, new Request('GET', '/'), 429, elapsed: 5.0, headers: ['Retry-After' => '6']))->toBeNull();
});

it('refuses settings that make no sense', function (): void {
    expect(fn () => new RetryPolicy(maxAttempts: 0))->toThrow(InvalidRequestException::class)
        ->and(fn () => new RetryPolicy(jitter: 1.5))->toThrow(InvalidRequestException::class);
});
