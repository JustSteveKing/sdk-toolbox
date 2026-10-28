<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\OAuthClient;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\ResponseInterface;

function oauthClient(FakeClient $http, FakeTime $time, ?string $secret = 's3cr+t'): OAuthClient
{
    return new OAuthClient(
        tokenEndpoint: 'https://auth.acme.test/oauth/token',
        clientId: 'acme app',
        clientSecret: $secret,
        authorizationEndpoint: 'https://auth.acme.test/oauth/authorize',
        deviceAuthorizationEndpoint: 'https://auth.acme.test/oauth/device',
        http: $http,
        clock: $time,
    );
}

function token(string $token, int $expiresIn = 3600, ?string $refresh = null, ?string $scope = null): ResponseInterface
{
    return FakeResponse::json(array_filter([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
        'refresh_token' => $refresh,
        'scope' => $scope,
    ], fn (mixed $v): bool => $v !== null));
}

function oauthError(string $error, int $status = 400): ResponseInterface
{
    return FakeResponse::json(['error' => $error, 'error_description' => "The server said {$error}."], $status);
}

/**
 * @return array<string, string>
 */
function form(Psr\Http\Message\RequestInterface $request): array
{
    parse_str((string) $request->getBody(), $form);

    /** @var array<string, string> $form */
    return $form;
}
