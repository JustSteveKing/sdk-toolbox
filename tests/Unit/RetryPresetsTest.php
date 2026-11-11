<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Nyholm\Psr7\Request;

function getProjects(): Request
{
    return new Request('GET', 'https://api.acme.test/projects');
}

function rateLimited(int $seconds): Psr\Http\Message\ResponseInterface
{
    return FakeResponse::json(['error' => 'slow down'], 429, ['Retry-After' => (string) $seconds]);
}

it('waits out a short Retry-After when someone is waiting', function (): void {
    expect(RetryPolicy::interactive()->delay(getProjects(), rateLimited(3), null, 1, 0.0, new FakeTime))->toBe(3.0);
});

it('gives up on a Retry-After someone waiting would not sit through', function (): void {
    expect(RetryPolicy::interactive()->delay(getProjects(), rateLimited(30), null, 1, 0.0, new FakeTime))->toBeNull()
        ->and(RetryPolicy::standard()->delay(getProjects(), rateLimited(30), null, 1, 0.0, new FakeTime))->toBe(30.0);
});

it('ends the whole sequence within ten seconds', function (): void {
    expect(RetryPolicy::interactive()->delay(getProjects(), rateLimited(4), null, 2, 7.0, new FakeTime))->toBeNull()
        ->and(RetryPolicy::interactive()->delay(getProjects(), rateLimited(4), null, 2, 5.0, new FakeTime))->toBe(4.0);
});

it('caps backoff at two seconds', function (): void {
    $policy = RetryPolicy::interactive();

    expect($policy->maxDelay)->toBe(2.0)
        ->and($policy->maxAttempts)->toBe(3);
});

it('keeps the same rules about what is safe to retry', function (): void {
    $post = new Request('POST', 'https://api.acme.test/charges');

    expect(RetryPolicy::interactive()->delay($post, FakeResponse::json([], 500), null, 1, 0.0, new FakeTime))->toBeNull();
});
