<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use JustSteveKing\SdkToolbox\Auth\Refreshable;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * The client credentials grant (RFC 6749 section 4.4): a server-to-server
 * token, fetched when needed, cached until it expires, fetched again when
 * it does or when the API rejects it.
 *
 * Only for confidential clients (section 4.4), so a client secret is
 * required. The server SHOULD NOT issue a refresh token here (section
 * 4.4.3), so renewing is simply asking again.
 *
 *     auth: new ClientCredentials($oauth, scopes: ['projects:read'], store: new Psr16TokenStore($cache)),
 */
final readonly class ClientCredentials implements Refreshable
{
    use BearerFromProvider;

    private TokenProvider $provider;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        OAuthClient $client,
        array $scopes = [],
        TokenStore $store = new InMemoryTokenStore,
        RefreshLock $lock = new ProcessLock,
        int $leeway = 30,
    ) {
        if ($client->isPublic()) {
            throw new InvalidRequestException('The client credentials grant is only for confidential clients (RFC 6749 section 4.4); this client has no secret.');
        }

        $this->provider = new TokenProvider(
            key: implode('|', ['client_credentials', $client->tokenEndpoint, $client->clientId, ...$scopes]),
            store: $store,
            lock: $lock,
            clock: $client->clock,
            fetch: static fn (): AccessToken => $client->requestToken(
                ['grant_type' => 'client_credentials', 'scope' => $scopes === [] ? null : implode(' ', $scopes)],
                $scopes,
            ),
            leeway: $leeway,
        );
    }
}
