<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\DeviceAuthorization;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

function deviceStart(int $interval = 5, int $expiresIn = 900): Psr\Http\Message\ResponseInterface
{
    return FakeResponse::json([
        'device_code' => 'GmRhmhcxhwAzkoEqiMEg_DnyEysNkuNhszIySk9eS',
        'user_code' => 'WDJB-MJHT',
        'verification_uri' => 'https://auth.acme.test/device',
        'verification_uri_complete' => 'https://auth.acme.test/device?user_code=WDJB-MJHT',
        'expires_in' => $expiresIn,
        'interval' => $interval,
    ]);
}

it('starts with what the user needs to see (RFC 8628 3.2)', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(deviceStart());

    $code = (new DeviceAuthorization(oauthClient($auth, $time), $time))->start(['projects:read']);

    expect($code->userCode)->toBe('WDJB-MJHT')
        ->and($code->verificationUri)->toBe('https://auth.acme.test/device')
        ->and($code->interval)->toBe(5)
        ->and((string) $auth->last()?->getUri())->toBe('https://auth.acme.test/oauth/device')
        ->and(form($auth->last()))->toBe(['scope' => 'projects:read']);
});

it('polls at the interval, slows down by 5 seconds when told, and returns the token (RFC 8628 3.5)', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(
        deviceStart(interval: 5),
        oauthError('authorization_pending'),
        oauthError('slow_down'),
        oauthError('authorization_pending'),
        token('device-token'),
    );
    $flow = new DeviceAuthorization(oauthClient($auth, $time), $time);

    $token = $flow->poll($flow->start());

    expect($token->token)->toBe('device-token')
        ->and($time->sleeps())->toBe([5.0, 5.0, 10.0, 10.0])
        ->and(form($auth->last())['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:device_code');
});

it('backs off after a connection failure', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(deviceStart(interval: 5), FakeResponse::networkError(), token('t'));
    $flow = new DeviceAuthorization(oauthClient($auth, $time), $time);

    $flow->poll($flow->start());

    expect($time->sleeps())->toBe([5.0, 10.0]);
});

it('stops when the user says no', function (): void {
    $time = new FakeTime;
    $flow = new DeviceAuthorization(oauthClient(new FakeClient(deviceStart(), oauthError('access_denied')), $time), $time);

    expect(fn () => $flow->poll($flow->start()))->toThrow(OAuthException::class, 'access_denied');
});

it('stops when the code expires', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(deviceStart(interval: 5, expiresIn: 12), oauthError('authorization_pending'), oauthError('authorization_pending'));
    $flow = new DeviceAuthorization(oauthClient($auth, $time), $time);

    expect(fn () => $flow->poll($flow->start()))->toThrow(OAuthException::class, 'expired_token');
    $auth->assertSentCount(3);
});
