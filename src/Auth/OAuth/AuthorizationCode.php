<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Http\QueryString;

/**
 * The authorisation code grant (RFC 6749 section 4.1) with PKCE, for an
 * application acting for a user.
 *
 *     $started = $flow->start(['projects:read']);
 *     $session->put('oauth', $started);          // keep state and verifier
 *     return redirect($started->url);
 *
 *     // ...then on the redirect back:
 *     $token = $flow->exchange($session->pull('oauth'), $request->query('code'), $request->query('state'));
 *
 * PKCE is used for every client, confidential ones included, as RFC 9700
 * recommends, and `state` is checked as well.
 */
final readonly class AuthorizationCode
{
    public function __construct(
        private OAuthClient $client,
        private string $redirectUri,
    ) {
        if ($client->authorizationEndpoint === null) {
            throw new InvalidRequestException('The authorisation code grant needs the client\'s authorizationEndpoint.');
        }
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $parameters  anything else the server takes, such as `prompt`
     */
    public function start(array $scopes = [], array $parameters = []): AuthorizationRequest
    {
        $pkce = Pkce::generate();
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $query = QueryString::build([
            ...$parameters,
            'response_type' => 'code',
            'client_id' => $this->client->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->client->scope($scopes),
            'state' => $state,
            'code_challenge' => $pkce->challenge(),
            'code_challenge_method' => $pkce->method(),
        ]);

        $endpoint = (string) $this->client->authorizationEndpoint;
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return new AuthorizationRequest("{$endpoint}{$separator}{$query}", $state, $pkce);
    }

    /**
     * @param  list<string>  $scopes  as asked for in start(), used when the server doesn't list what it granted
     *
     * @throws OAuthException `invalid_state` when the state doesn't match, or the server's error
     */
    public function exchange(AuthorizationRequest $started, string $code, string $state, array $scopes = []): AccessToken
    {
        if (! hash_equals($started->state, $state)) {
            throw new OAuthException('invalid_state', 'The state returned doesn\'t match the one sent; the response may be forged.');
        }

        return $this->client->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $started->pkce->verifier,
        ], $scopes);
    }
}
