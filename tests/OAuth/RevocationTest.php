<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\AccessToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientAuthentication;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientCredentials;
use JustSteveKing\SdkToolbox\Auth\OAuth\InMemoryTokenStore;
use JustSteveKing\SdkToolbox\Auth\OAuth\OAuthClient;
use JustSteveKing\SdkToolbox\Auth\OAuth\ProcessLock;
use JustSteveKing\SdkToolbox\Auth\OAuth\RefreshingToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\TokenProvider;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;

function revokingClient(FakeClient $http, FakeTime $time, ?string $secret = 's3cr+t', ClientAuthentication $authentication = ClientAuthentication::Basic): OAuthClient
{
    return new OAuthClient(
        tokenEndpoint: 'https://auth.acme.test/oauth/token',
        clientId: 'acme app',
        clientSecret: $secret,
        authentication: $authentication,
        http: $http,
        clock: $time,
        revocationEndpoint: 'https://auth.acme.test/oauth/revoke',
    );
}

it('posts the token and its hint to the revocation endpoint, with Basic client authentication (RFC 7009 2.1)', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));

    revokingClient($http, new FakeTime)->revoke('refresh-1', 'refresh_token');

    $sent = $http->last();

    expect($sent?->getMethod())->toBe('POST')
        ->and((string) $sent?->getUri())->toBe('https://auth.acme.test/oauth/revoke')
        ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and($sent?->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('acme+app:s3cr%2Bt'))
        ->and(form($sent))->toBe(['token' => 'refresh-1', 'token_type_hint' => 'refresh_token']);
});

it('authenticates in the body when the client does, and sends a public client\'s ID', function (): void {
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200));

    revokingClient($http, new FakeTime, authentication: ClientAuthentication::Post)->revoke('t');
    expect(form($http->last()))->toBe(['token' => 't', 'client_id' => 'acme app', 'client_secret' => 's3cr+t'])
        ->and($http->last()?->hasHeader('Authorization'))->toBeFalse();

    revokingClient($http, new FakeTime, secret: null)->revoke('t');
    expect(form($http->last()))->toBe(['token' => 't', 'client_id' => 'acme app']);
});

it('hints an AccessToken as an access token unless told otherwise', function (): void {
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200));
    $client = revokingClient($http, new FakeTime);

    $client->revoke(new AccessToken('a-1'));
    $client->revoke(new AccessToken('a-1'), 'refresh_token');

    expect(array_map(fn (RequestInterface $r): array => form($r), $http->sent()))->toBe([
        ['token' => 'a-1', 'token_type_hint' => 'access_token'],
        ['token' => 'a-1', 'token_type_hint' => 'refresh_token'],
    ]);
});

it('takes a 200 with any body as success, and reads an error response (RFC 7009 2.2.1)', function (): void {
    $http = new FakeClient(
        FakeResponse::json(['revoked' => true]),
        oauthError('unsupported_token_type'),
        FakeResponse::empty(503),
    );
    $client = revokingClient($http, new FakeTime);

    $client->revoke('t');

    expect(fn () => $client->revoke('t'))->toThrow(OAuthException::class, 'unsupported_token_type')
        ->and(fn () => $client->revoke('t'))->toThrow(OAuthException::class, 'invalid_response');
});

it('refuses to revoke without a revocation endpoint, sending nothing', function (): void {
    $http = new FakeClient;

    expect(fn () => oauthClient($http, new FakeTime)->revoke('t'))->toThrow(InvalidRequestException::class);
    $http->assertNothingSent();
});

it('refuses a revocation endpoint without TLS', function (): void {
    expect(fn () => new OAuthClient('https://auth.acme.test/token', 'id', revocationEndpoint: 'http://auth.acme.test/revoke', http: new FakeClient))
        ->toThrow(InvalidRequestException::class);
});

it('reads the endpoints from authorization server metadata (RFC 8414 2)', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));
    $client = OAuthClient::fromMetadata([
        'issuer' => 'https://auth.acme.test',
        'authorization_endpoint' => 'https://auth.acme.test/authorize',
        'token_endpoint' => 'https://auth.acme.test/token',
        'device_authorization_endpoint' => 'https://auth.acme.test/device',
        'revocation_endpoint' => 'https://auth.acme.test/revoke',
    ], 'id', 'secret', http: $http);

    $client->revoke('t');

    expect($client->tokenEndpoint)->toBe('https://auth.acme.test/token')
        ->and($client->authorizationEndpoint)->toBe('https://auth.acme.test/authorize')
        ->and($client->deviceAuthorizationEndpoint)->toBe('https://auth.acme.test/device')
        ->and((string) $http->last()?->getUri())->toBe('https://auth.acme.test/revoke');
});

it('refuses metadata without a token endpoint, and revokes nothing for metadata without a revocation endpoint', function (): void {
    expect(fn () => OAuthClient::fromMetadata(['issuer' => 'https://auth.acme.test'], 'id', http: new FakeClient))->toThrow(OAuthException::class)
        ->and(fn () => OAuthClient::fromMetadata(['token_endpoint' => 'https://auth.acme.test/token'], 'id', http: new FakeClient)->revoke('t'))->toThrow(InvalidRequestException::class);
});

it('revokes the refresh token then the access token, and forgets them', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('a-1', $time->now()->modify('+1 hour'), 'r-1'));
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200));

    (new RefreshingToken(revokingClient($http, $time), 'user:1', $store))->revoke();

    expect(array_map(fn (RequestInterface $r): array => form($r), $http->sent()))->toBe([
        ['token' => 'r-1', 'token_type_hint' => 'refresh_token'],
        ['token' => 'a-1', 'token_type_hint' => 'access_token'],
    ])->and($store->get('user:1'))->toBeNull();
});

it('keeps the token stored when the server refuses, so it can be revoked again', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('a-1', refreshToken: 'r-1'));
    $auth = new RefreshingToken(revokingClient(new FakeClient(oauthError('invalid_client', 401)), $time), 'user:1', $store);

    expect(fn () => $auth->revoke())->toThrow(OAuthException::class, 'invalid_client')
        ->and($store->get('user:1')?->token)->toBe('a-1');
});

it('revokes a client credentials token, and does nothing when none is stored', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $http = new FakeClient(token('cc-1'), FakeResponse::empty(200));
    $client = revokingClient($http, $time);
    $auth = new ClientCredentials($client, store: $store);

    $auth->revoke();
    $http->assertNothingSent();

    $auth->authenticate(new Nyholm\Psr7\Request('GET', 'https://api.acme.test'));
    $auth->revoke();

    expect(form($http->last()))->toBe(['token' => 'cc-1', 'token_type_hint' => 'access_token']);
    $http->assertSentCount(2);
});

it('revokes through a TokenProvider for a grant of your own', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('acme:jwt', new AccessToken('j-1'));
    $http = new FakeClient(FakeResponse::empty(200));
    $provider = new TokenProvider('acme:jwt', $store, new ProcessLock, $time, fn (): AccessToken => new AccessToken('unused'));

    $provider->revoke(revokingClient($http, $time));

    expect(form($http->last()))->toBe(['token' => 'j-1', 'token_type_hint' => 'access_token'])
        ->and($store->get('acme:jwt'))->toBeNull();
});
