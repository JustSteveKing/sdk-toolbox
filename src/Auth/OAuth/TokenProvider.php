<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Closure;
use Psr\Clock\ClockInterface;

/**
 * Hands out a valid token, fetching a new one when it must, once.
 *
 * A refresh runs inside the lock, and the first thing it does there is
 * look again: if another process stored a new token while this one waited,
 * that token is used and nothing is fetched. A token the API rejected is
 * never handed out again, even if its expiry says it's fine.
 *
 * ClientCredentials and RefreshingToken are built on it. For a grant the
 * toolbox doesn't have, such as RFC 7523's JWT bearer grant or RFC 8693's
 * token exchange, give it a `fetch` that gets a token your way, and pass
 * it to ProvidedToken:
 *
 *     auth: new ProvidedToken(new TokenProvider(
 *         key: 'acme:jwt-bearer',
 *         store: $store,
 *         lock: $lock,
 *         clock: $oauth->clock,
 *         fetch: fn (?AccessToken $current): AccessToken => $oauth->requestToken([
 *             'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
 *             'assertion' => $assertions->make(),
 *         ]),
 *     )),
 */
final readonly class TokenProvider
{
    /**
     * @param  Closure(?AccessToken): AccessToken  $fetch  given the token being replaced, if any
     */
    public function __construct(
        private string $key,
        private TokenStore $store,
        private RefreshLock $lock,
        private ClockInterface $clock,
        private Closure $fetch,
        private int $leeway = 30,
    ) {}

    public function current(): AccessToken
    {
        $token = $this->store->get($this->key);

        return $token !== null && ! $token->expired($this->clock, $this->leeway)
            ? $token
            : $this->refresh($token?->token);
    }

    public function refresh(?string $rejected): AccessToken
    {
        return $this->lock->synchronized($this->key, function () use ($rejected): AccessToken {
            $stored = $this->store->get($this->key);

            if ($stored !== null && $stored->token !== $rejected && ! $stored->expired($this->clock, $this->leeway)) {
                return $stored;
            }

            $fresh = ($this->fetch)($stored);
            $this->store->put($this->key, $fresh);

            return $fresh;
        });
    }
}
