<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Nyholm\Psr7\Request;

/*
 * Resend answers 409 for two things: a request with the same key still
 * running (worth retrying) and a key reused with a different body (never
 * worth it). The status rules retry both; the veto tells them apart.
 */

function keyedPost(): Request
{
    return new Request('POST', 'https://api.resend.com/emails', ['Idempotency-Key' => 'welcome/42']);
}

function conflict(string $name): Psr\Http\Message\ResponseInterface
{
    return FakeResponse::json(['statusCode' => 409, 'name' => $name, 'message' => 'Conflict'], 409);
}

function resendVeto(): RetryPolicy
{
    return (new RetryPolicy(jitter: 0.0))->withoutRetryingWhen(
        static fn (Response $response): bool => $response->get('name') === 'invalid_idempotent_request',
    );
}

it('still retries what the veto allows', function (): void {
    expect(resendVeto()->delay(keyedPost(), conflict('concurrent_idempotent_requests'), null, 1, 0.0, new FakeTime))->toBe(0.5);
});

it('stops on what the veto refuses, whatever the status says', function (): void {
    expect(resendVeto()->delay(keyedPost(), conflict('invalid_idempotent_request'), null, 1, 0.0, new FakeTime))->toBeNull();
});

it('would retry that response without the veto', function (): void {
    expect((new RetryPolicy(jitter: 0.0))->delay(keyedPost(), conflict('invalid_idempotent_request'), null, 1, 0.0, new FakeTime))->toBe(0.5);
});

it('leaves the body readable after the veto has read it', function (): void {
    $response = conflict('invalid_idempotent_request');

    resendVeto()->delay(keyedPost(), $response, null, 1, 0.0, new FakeTime);

    expect((string) $response->getBody())->toContain('invalid_idempotent_request');
});

it('never makes a retry the status rules would not', function (): void {
    $policy = (new RetryPolicy(jitter: 0.0))->withoutRetryingWhen(static fn (Response $response): bool => false);

    expect($policy->delay(keyedPost(), FakeResponse::json(['error' => 'bad'], 422), null, 1, 0.0, new FakeTime))->toBeNull();
});

it('applies every veto when called more than once', function (): void {
    $policy = resendVeto()->withoutRetryingWhen(static fn (Response $response): bool => $response->get('name') === 'daily_quota_exceeded');
    $quota = FakeResponse::json(['name' => 'daily_quota_exceeded'], 429);

    expect($policy->delay(keyedPost(), $quota, null, 1, 0.0, new FakeTime))->toBeNull()
        ->and($policy->delay(keyedPost(), conflict('invalid_idempotent_request'), null, 1, 0.0, new FakeTime))->toBeNull()
        ->and($policy->delay(keyedPost(), conflict('concurrent_idempotent_requests'), null, 1, 0.0, new FakeTime))->toBe(0.5);
});

it('keeps the veto through the other with*() copies', function (): void {
    $policy = resendVeto()->withMaxAttempts(5)->withBackoff(1.0, 4.0)->withIdempotencyHeader('Idempotency-Key');

    expect($policy->delay(keyedPost(), conflict('invalid_idempotent_request'), null, 1, 0.0, new FakeTime))->toBeNull();
});

it('is not asked when there is no response', function (): void {
    $asked = false;
    $policy = (new RetryPolicy(jitter: 0.0))->withoutRetryingWhen(function () use (&$asked): bool {
        $asked = true;

        return true;
    });

    $policy->delay(keyedPost(), null, new JustSteveKing\SdkToolbox\Testing\FakeNetworkException(keyedPost()), 1, 0.0, new FakeTime);

    expect($asked)->toBeFalse();
});
