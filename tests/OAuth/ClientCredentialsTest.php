<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\ClientAuthentication;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientCredentials;
use JustSteveKing\SdkToolbox\Auth\OAuth\InMemoryTokenStore;
use JustSteveKing\SdkToolbox\Auth\OAuth\OAuthClient;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Errors\UnauthorizedException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

function api(FakeClient $http, ClientCredentials $auth, FakeTime $time): Connector
{
    return new Connector('https://api.acme.test', auth: $auth, http: $http, clock: $time, sleeper: $time);
}

it('fetches a token once and reuses it until it expires', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('t1', 3600), token('t2', 3600));
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200), FakeResponse::empty(200));
    $connector = api($http, new ClientCredentials(oauthClient($auth, $time), ['projects:read']), $time);

    $connector->response(ping());
    $connector->response(ping());
    $auth->assertSentCount(1);

    $time->advance(3600 - 29);  // inside the 30 second leeway
    $connector->response(ping());
    $auth->assertSentCount(2);

    expect(array_map(fn ($r): string => $r->getHeaderLine('Authorization'), $http->sent()))->toBe(['Bearer t1', 'Bearer t1', 'Bearer t2']);
});

it('asks for the grant and scope, authenticating with form-encoded Basic credentials (RFC 6749 2.3.1)', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('t1'));

    api(new FakeClient(FakeResponse::empty(200)), new ClientCredentials(oauthClient($auth, $time), ['a', 'b']), $time)->response(ping());

    $request = $auth->last();
    expect($request?->getMethod())->toBe('POST')
        ->and((string) $request?->getUri())->toBe('https://auth.acme.test/oauth/token')
        ->and(form($request))->toBe(['grant_type' => 'client_credentials', 'scope' => 'a b'])
        // "acme app" and "s3cr+t" are form-encoded first: "acme+app:s3cr%2Bt".
        ->and($request?->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('acme+app:s3cr%2Bt'));
});

it('can send the client credentials in the body instead', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('t1'));
    $client = new OAuthClient('https://auth.acme.test/oauth/token', 'id', 'secret', authentication: ClientAuthentication::Post, http: $auth, clock: $time);

    api(new FakeClient(FakeResponse::empty(200)), new ClientCredentials($client), $time)->response(ping());

    expect(form($auth->last()))->toBe(['grant_type' => 'client_credentials', 'client_id' => 'id', 'client_secret' => 'secret'])
        ->and($auth->last()?->hasHeader('Authorization'))->toBeFalse();
});

it('gets a new token and retries once when the API says invalid_token (RFC 6750 3.1)', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('revoked'), token('fresh'));
    $http = new FakeClient(
        FakeResponse::empty(401, ['WWW-Authenticate' => 'Bearer realm="acme", error="invalid_token"']),
        FakeResponse::empty(200),
    );

    $response = api($http, new ClientCredentials(oauthClient($auth, $time)), $time)->response(ping());

    expect($response->status())->toBe(200)
        ->and($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer fresh');
});

it('also retries a bare 401, as many APIs send', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(401), FakeResponse::empty(200));

    expect(api($http, new ClientCredentials(oauthClient(new FakeClient(token('a'), token('b')), $time)), $time)->response(ping())->status())->toBe(200);
});

it('retries only once', function (): void {
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(401), FakeResponse::empty(401));

    expect(fn () => api($http, new ClientCredentials(oauthClient(new FakeClient(token('a'), token('b')), $time)), $time)->send(ping()))
        ->toThrow(UnauthorizedException::class);
    $http->assertSentCount(2);
});

it('does not refresh for errors new credentials will not fix', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('a'));
    $http = new FakeClient(FakeResponse::empty(401, ['WWW-Authenticate' => 'Bearer error="invalid_request"']));

    api($http, new ClientCredentials(oauthClient($auth, $time)), $time)->response(ping());

    $auth->assertSentCount(1);
    $http->assertSentCount(1);
});

it('surfaces the server\'s error (RFC 6749 5.2)', function (): void {
    $time = new FakeTime;

    try {
        api(new FakeClient, new ClientCredentials(oauthClient(new FakeClient(oauthError('invalid_client', 401)), $time)), $time)->response(ping());
        $this->fail('Expected an OAuthException');
    } catch (OAuthException $e) {
        expect($e->error)->toBe('invalid_client')
            ->and($e->status)->toBe(401)
            ->and($e->getMessage())->toBe('OAuth error: invalid_client: The server said invalid_client.');
    }
});

it('refuses a token type it does not understand (RFC 6749 7.1)', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(FakeResponse::json(['access_token' => 'x', 'token_type' => 'mac']));

    expect(fn () => api(new FakeClient, new ClientCredentials(oauthClient($auth, $time)), $time)->response(ping()))
        ->toThrow(OAuthException::class, 'unsupported_token_type');
});

it('is only for confidential clients (RFC 6749 4.4)', function (): void {
    expect(fn () => new ClientCredentials(oauthClient(new FakeClient, new FakeTime, secret: null)))->toThrow(InvalidRequestException::class);
});

it('refuses token endpoints without TLS', function (): void {
    expect(fn () => new OAuthClient('http://auth.acme.test/token', 'id', 'secret', http: new FakeClient))->toThrow(InvalidRequestException::class);
});

it('keeps secrets out of dumps', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $credentials = new ClientCredentials(oauthClient(new FakeClient(token('the-access-token')), $time), store: $store);
    api(new FakeClient(FakeResponse::empty(200)), $credentials, $time)->response(ping());

    expect(print_r($credentials, true).print_r($store, true).print_r(oauthClient(new FakeClient, $time), true))
        ->not->toContain('the-access-token')
        ->not->toContain('s3cr+t');
});
