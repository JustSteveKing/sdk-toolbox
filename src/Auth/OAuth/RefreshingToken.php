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
 *
 * When a reply has no refresh token, RFC 6749 section 6 lets the client
 * go on using the old one, and that's the default. A server that rotates
 * refresh tokens (RFC 9700 section 4.14.2) may instead omit it when the
 * old one is already spent, as Todoist does when a refresh is retried
 * within its grace window; presenting the spent one later reads as a
 * replay, and the server revokes the whole grant. For such a server pass
 * `rotating: true`, and a reply without one leaves no refresh token
 * stored: the user authorises again when the access token expires.
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
        bool $rotating = false,
    ) {
        $this->provider = new TokenProvider(
            key: $key,
            store: $store,
            lock: $lock,
            clock: $client->clock,
            fetch: static function (?AccessToken $current) use ($client, $rotating): AccessToken {
                if ($current?->refreshToken === null) {
                    throw new OAuthException('no_refresh_token', 'There is no refresh token for this key; the user has to authorise again.');
                }

                $fresh = $client->requestToken(
                    ['grant_type' => 'refresh_token', 'refresh_token' => $current->refreshToken],
                    $current->scopes,
                );

                // A server that doesn't issue a new refresh token expects the
                // old one to go on being used, unless it rotates them, when
                // the old one is spent.
                return $fresh->refreshToken !== null || $rotating ? $fresh : new AccessToken(
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
