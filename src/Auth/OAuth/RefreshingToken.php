<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use JustSteveKing\SdkToolbox\Auth\Refreshable;
use JustSteveKing\SdkToolbox\Errors\OAuthException;

/**
 * A user's token from the authorisation code or device flow, kept fresh
 * with its refresh token (RFC 6749 section 6).
 *
 * Store the token AuthorizationCode::exchange() or DeviceAuthorization::poll()
 * gave you under a key of your own, usually the user's ID, and this keeps
 * it valid from then on. When the server issues a new refresh token, the
 * old one is discarded, as section 6 requires; refresh-token rotation
 * (RFC 9700) depends on that.
 *
 *     $store->put("user:{$user->id}", $oauth->exchange(...));
 *
 *     auth: new RefreshingToken($oauth, "user:{$user->id}", $store, $lock),
 */
final readonly class RefreshingToken implements Refreshable
{
    use BearerFromProvider;

    private TokenProvider $provider;

    public function __construct(
        OAuthClient $client,
        string $key,
        TokenStore $store,
        RefreshLock $lock = new ProcessLock,
        int $leeway = 30,
    ) {
        $this->provider = new TokenProvider(
            key: $key,
            store: $store,
            lock: $lock,
            clock: $client->clock,
            fetch: static function (?AccessToken $current) use ($client): AccessToken {
                if ($current?->refreshToken === null) {
                    throw new OAuthException('no_refresh_token', 'There is no refresh token for this key; the user has to authorise again.');
                }

                $fresh = $client->requestToken(
                    ['grant_type' => 'refresh_token', 'refresh_token' => $current->refreshToken],
                    $current->scopes,
                );

                // A server that doesn't issue a new refresh token expects the
                // old one to go on being used.
                return $fresh->refreshToken !== null ? $fresh : new AccessToken(
                    $fresh->token,
                    $fresh->expiresAt,
                    $current->refreshToken,
                    $fresh->scopes,
                );
            },
            leeway: $leeway,
        );
    }
}
