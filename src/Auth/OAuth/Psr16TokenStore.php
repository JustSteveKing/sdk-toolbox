<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * Tokens in any PSR-16 cache, as plain arrays.
 *
 * Never PHP's serialize(): a token store that unserializes whatever the
 * cache holds is a remote code execution waiting for a writable cache,
 * which is exactly the hole Saloon closed in v4. Tokens are secrets, so
 * the cache should be one only your application can read.
 */
final readonly class Psr16TokenStore implements TokenStore
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'sdk-toolbox.token.',
    ) {}

    public function get(string $key): ?AccessToken
    {
        $data = $this->cache->get($this->key($key));

        if (! is_array($data) || ! is_string($data['token'] ?? null)) {
            return null;
        }

        $expiresAt = $data['expires_at'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $scopes = $data['scopes'] ?? [];

        return new AccessToken(
            token: $data['token'],
            expiresAt: is_int($expiresAt) ? new DateTimeImmutable("@{$expiresAt}") : null,
            refreshToken: is_string($refresh) ? $refresh : null,
            scopes: is_array($scopes) ? array_values(array_filter($scopes, is_string(...))) : [],
        );
    }

    public function put(string $key, AccessToken $token): void
    {
        // Kept past the access token's expiry when there's a refresh token,
        // which outlives it.
        $ttl = $token->refreshToken === null && $token->expiresAt !== null
            ? max(1, $token->expiresAt->getTimestamp() - time())
            : null;

        $this->cache->set($this->key($key), [
            'token' => $token->token,
            'expires_at' => $token->expiresAt?->getTimestamp(),
            'refresh_token' => $token->refreshToken,
            'scopes' => $token->scopes,
        ], $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->delete($this->key($key));
    }

    private function key(string $key): string
    {
        return $this->prefix.hash('xxh128', $key);
    }
}
