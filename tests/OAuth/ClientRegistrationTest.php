<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\AuthorizationCode;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientMetadata;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientRegistration;
use JustSteveKing\SdkToolbox\Auth\OAuth\RegisteredClient;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

/**
 * RFC 7591 section 3.2.1's example response.
 *
 * @return array<string, mixed>
 */
function rfc7591Response(): array
{
    return [
        'client_id' => 's6BhdRkqt3',
        'client_secret' => 'cf136dc3c1fc93f31185e5885805d',
        'client_id_issued_at' => 2893256800,
        'client_secret_expires_at' => 2893276800,
        'redirect_uris' => ['https://client.example.org/callback', 'https://client.example.org/callback2'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'client_name' => 'My Example Client',
        'client_name#ja-Jpan-JP' => "\u{30AF}\u{30E9}\u{30A4}\u{30A2}\u{30F3}\u{30C8}\u{540D}",
        'token_endpoint_auth_method' => 'client_secret_basic',
        'logo_uri' => 'https://client.example.org/logo.png',
        'jwks_uri' => 'https://client.example.org/my_public_keys.jwks',
        'example_extension_parameter' => 'example_value',
    ];
}

it('sends RFC 7591\'s example registration request as JSON (3.1)', function (): void {
    $http = new FakeClient(FakeResponse::json(rfc7591Response(), 201));

    (new ClientRegistration('https://server.example.com/register', $http))->register(new ClientMetadata(
        redirectUris: ['https://client.example.org/callback', 'https://client.example.org/callback2'],
        clientName: 'My Example Client',
        logoUri: 'https://client.example.org/logo.png',
        jwksUri: 'https://client.example.org/my_public_keys.jwks',
        extra: ['client_name#ja-Jpan-JP' => "\u{30AF}\u{30E9}\u{30A4}\u{30A2}\u{30F3}\u{30C8}\u{540D}", 'example_extension_parameter' => 'example_value'],
    ));

    $request = $http->last();

    expect($request?->getMethod())->toBe('POST')
        ->and((string) $request?->getUri())->toBe('https://server.example.com/register')
        ->and($request?->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request?->hasHeader('Authorization'))->toBeFalse()
        ->and(json_decode((string) $request?->getBody(), true))->toEqualCanonicalizing([
            'redirect_uris' => ['https://client.example.org/callback', 'https://client.example.org/callback2'],
            'client_name' => 'My Example Client',
            'client_name#ja-Jpan-JP' => "\u{30AF}\u{30E9}\u{30A4}\u{30A2}\u{30F3}\u{30C8}\u{540D}",
            'token_endpoint_auth_method' => 'client_secret_basic',
            'logo_uri' => 'https://client.example.org/logo.png',
            'jwks_uri' => 'https://client.example.org/my_public_keys.jwks',
            'example_extension_parameter' => 'example_value',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ]);
});

it('reads RFC 7591\'s example response (3.2.1)', function (): void {
    $client = (new ClientRegistration('https://server.example.com/register', new FakeClient(FakeResponse::json(rfc7591Response(), 201))))
        ->register(new ClientMetadata(['https://client.example.org/callback']));

    expect($client->clientId)->toBe('s6BhdRkqt3')
        ->and($client->clientSecret())->toBe('cf136dc3c1fc93f31185e5885805d')
        ->and($client->clientIdIssuedAt?->getTimestamp())->toBe(2893256800)
        ->and($client->clientSecretExpiresAt?->getTimestamp())->toBe(2893276800)
        ->and($client->tokenEndpointAuthMethod())->toBe('client_secret_basic')
        ->and($client->metadata['example_extension_parameter'])->toBe('example_value')
        ->and($client->metadata)->not->toHaveKey('client_secret')
        ->and(print_r($client, true))->not->toContain('cf136dc3c1fc93f31185e5885805d');
});

it('sends an initial access token as a Bearer token, and keeps it out of failures', function (): void {
    $http = new FakeClient(FakeResponse::json(rfc7591Response(), 201));

    (new ClientRegistration('https://server.example.com/register', $http))->register(new ClientMetadata(['https://app.test/cb']), 'ey23f2.adfj230.af32-developer321');

    expect($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer ey23f2.adfj230.af32-developer321');

    $down = new FakeClient(FakeResponse::networkError());

    // As for every TransportException, the client's own exception stays
    // unmasked as getPrevious() (docs/security.md); what the toolbox adds is masked.
    try {
        (new ClientRegistration('https://server.example.com/register', $down))->register(new ClientMetadata(['https://app.test/cb']), 'ey23f2.adfj230.af32-developer321');
        $this->fail('Expected a TransportException');
    } catch (TransportException $e) {
        expect($e->getMessage())->not->toContain('af32-developer321')
            ->and(print_r($e->request, true))->not->toContain('af32-developer321');
    }
});

it('reads a secret that never expires as no expiry, and a public client as having no secret', function (): void {
    $client = RegisteredClient::fromResponse(['client_id' => 'tdd_1', 'client_secret_expires_at' => 0, 'token_endpoint_auth_method' => 'none']);

    expect($client->clientSecretExpiresAt)->toBeNull()
        ->and($client->isPublic())->toBeTrue();
});

it('surfaces the server\'s refusal (3.2.2)', function (): void {
    $http = new FakeClient(FakeResponse::json(['error' => 'invalid_redirect_uri', 'error_description' => 'The redirection URI http://sketchy.example.com is not allowed by this server.'], 400));

    expect(fn () => (new ClientRegistration('https://server.example.com/register', $http))->register(new ClientMetadata(['http://sketchy.example.com'])))
        ->toThrow(fn (OAuthException $e) => expect([$e->error, $e->status])->toBe(['invalid_redirect_uri', 400]));
});

it('refuses a response with no client_id', function (): void {
    expect(fn () => RegisteredClient::fromResponse(['client_secret' => 'x']))->toThrow(OAuthException::class, 'no client_id');
});

it('refuses a registration endpoint without TLS', function (): void {
    expect(fn () => new ClientRegistration('http://server.example.com/register', new FakeClient))->toThrow(InvalidRequestException::class);
});

it('builds an OAuthClient that authenticates the way the server registered it', function (string $method, bool $basic): void {
    $auth = new FakeClient(token('t'));
    $client = RegisteredClient::fromResponse(['client_id' => 'c1', 'client_secret' => 's1', 'token_endpoint_auth_method' => $method]);
    $flow = new AuthorizationCode($client->oauthClient('https://auth.acme.test/oauth/token', 'https://auth.acme.test/oauth/authorize', http: $auth, clock: new FakeTime), 'https://app.test/cb');
    $started = $flow->start();

    $flow->exchange($started, 'code', $started->state);

    expect($auth->last()?->hasHeader('Authorization'))->toBe($basic)
        ->and(isset(form($auth->last())['client_secret']))->toBe(! $basic);
})->with(['basic' => ['client_secret_basic', true], 'post' => ['client_secret_post', false]]);

it('refuses to build a client for a method it cannot use', function (): void {
    $client = RegisteredClient::fromResponse(['client_id' => 'c1', 'token_endpoint_auth_method' => 'private_key_jwt']);

    expect(fn () => $client->oauthClient('https://auth.acme.test/oauth/token'))->toThrow(OAuthException::class, 'private_key_jwt');
});
