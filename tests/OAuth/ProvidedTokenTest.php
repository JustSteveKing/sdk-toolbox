<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\AccessToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\InMemoryTokenStore;
use JustSteveKing\SdkToolbox\Auth\OAuth\ProcessLock;
use JustSteveKing\SdkToolbox\Auth\OAuth\ProvidedToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\TokenProvider;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;

it('runs a grant the toolbox does not have, fetching once and renewing on rejection', function (): void {
    $time = new FakeTime;
    $auth = new FakeClient(token('jwt-1'), token('jwt-2'));
    $oauth = oauthClient($auth, $time);
    $provider = new TokenProvider(
        key: 'acme:jwt-bearer',
        store: new InMemoryTokenStore,
        lock: new ProcessLock,
        clock: $time,
        fetch: fn (?AccessToken $current): AccessToken => $oauth->requestToken([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => 'signed.assertion.jwt',
        ]),
    );
    $api = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200), FakeResponse::empty(401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']), FakeResponse::empty(200));
    $connector = new Connector('https://api.acme.test', auth: new ProvidedToken($provider), http: $api, clock: $time, sleeper: $time);

    $connector->response(ping());
    $connector->response(ping());
    $connector->response(ping());

    expect(array_map(fn (RequestInterface $r): string => $r->getHeaderLine('Authorization'), $api->sent()))
        ->toBe(['Bearer jwt-1', 'Bearer jwt-1', 'Bearer jwt-1', 'Bearer jwt-2'])
        ->and(form($auth->sent()[0])['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
    $auth->assertSentCount(2);
});
