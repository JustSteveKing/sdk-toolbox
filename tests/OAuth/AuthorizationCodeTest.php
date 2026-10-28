<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\AuthorizationCode;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientCredentials;
use JustSteveKing\SdkToolbox\Auth\OAuth\OAuthClient;
use JustSteveKing\SdkToolbox\Auth\OAuth\Pkce;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

it('computes the S256 challenge from RFC 7636 Appendix B', function (): void {
    $pkce = new Pkce('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

    expect($pkce->challenge())->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and($pkce->method())->toBe('S256');
});

it('generates verifiers with 256 bits in the unreserved alphabet (RFC 7636 4.1)', function (): void {
    $verifier = Pkce::generate()->verifier;

    expect(strlen($verifier))->toBe(43)
        ->and($verifier)->toMatch('/^[A-Za-z0-9\-._~]+$/')
        ->and(Pkce::generate()->verifier)->not->toBe($verifier);
});

it('builds the authorisation URL with state and an S256 challenge', function (): void {
    $flow = new AuthorizationCode(oauthClient(new FakeClient, new FakeTime), 'https://app.test/callback');

    $started = $flow->start(['projects:read', 'offline'], ['prompt' => 'consent']);
    parse_str((string) parse_url($started->url, PHP_URL_QUERY), $query);

    expect($started->url)->toStartWith('https://auth.acme.test/oauth/authorize?')
        ->and($query)->toBe([
            'prompt' => 'consent',
            'response_type' => 'code',
            'client_id' => 'acme app',
            'redirect_uri' => 'https://app.test/callback',
            'scope' => 'projects:read offline',
            'state' => $started->state,
            'code_challenge' => $started->pkce->challenge(),
            'code_challenge_method' => 'S256',
        ]);
});

it('lists scopes with commas for a server that wants them', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('user-token', scope: 'data:read,data:delete'));
    $client = new OAuthClient('https://auth.acme.test/oauth/token', 'acme app', 's3cr+t', 'https://auth.acme.test/oauth/authorize', http: $auth, clock: $time, scopeSeparator: ',');
    $flow = new AuthorizationCode($client, 'https://app.test/callback');

    $started = $flow->start(['data:read', 'data:delete']);
    parse_str((string) parse_url($started->url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('data:read,data:delete')
        ->and($flow->exchange($started, 'the-code', $started->state)->scopes)->toBe(['data:read', 'data:delete']);
});

it('sends comma-listed scopes from every grant, not just the code flow', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('app-token'));
    $client = new OAuthClient('https://auth.acme.test/oauth/token', 'acme app', 's3cr+t', http: $auth, clock: $time, scopeSeparator: ',');

    (new ClientCredentials($client, ['a', 'b']))->authenticate(new Nyholm\Psr7\Request('GET', 'https://api.acme.test/'));

    expect(form($auth->last())['scope'])->toBe('a,b');
});

it('refuses an empty scope separator', function (): void {
    expect(fn () => new OAuthClient('https://auth.acme.test/oauth/token', 'acme app', scopeSeparator: ''))->toThrow(InvalidRequestException::class);
});

it('exchanges the code with the verifier', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('user-token', refresh: 'user-refresh', scope: 'projects:read'));
    $flow = new AuthorizationCode(oauthClient($auth, $time), 'https://app.test/callback');
    $started = $flow->start();

    $token = $flow->exchange($started, 'the-code', $started->state);

    expect($token->token)->toBe('user-token')
        ->and($token->refreshToken)->toBe('user-refresh')
        ->and($token->scopes)->toBe(['projects:read'])
        ->and($token->expiresAt)->toEqual($time->now()->modify('+3600 seconds'))
        ->and(form($auth->last()))->toBe([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => 'https://app.test/callback',
            'code_verifier' => $started->pkce->verifier,
        ]);
});

it('sends a public client\'s id in the body, with no secret', function (): void {
    $auth = new FakeClient(token('t'));
    $flow = new AuthorizationCode(oauthClient($auth, new FakeTime, secret: null), 'http://127.0.0.1:8123/callback');
    $started = $flow->start();

    $flow->exchange($started, 'code', $started->state);

    expect(form($auth->last()))->toHaveKey('client_id', 'acme app')->not->toHaveKey('client_secret')
        ->and($auth->last()?->hasHeader('Authorization'))->toBeFalse();
});

it('refuses a response whose state does not match', function (): void {
    $auth = new FakeClient;
    $flow = new AuthorizationCode(oauthClient($auth, new FakeTime), 'https://app.test/callback');

    expect(fn () => $flow->exchange($flow->start(), 'code', 'forged'))->toThrow(OAuthException::class, 'invalid_state');
    $auth->assertNothingSent();
});
