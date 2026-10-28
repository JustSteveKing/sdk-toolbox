<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * For one process. A web application wants Psr16TokenStore instead, or
 * every request fetches a new token.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, AccessToken> */
    private array $tokens = [];

    public function get(string $key): ?AccessToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function put(string $key, AccessToken $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
