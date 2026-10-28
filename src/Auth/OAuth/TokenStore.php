<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * Where tokens live between requests, and between processes when the
 * store is shared.
 */
interface TokenStore
{
    public function get(string $key): ?AccessToken;

    public function put(string $key, AccessToken $token): void;

    public function forget(string $key): void;
}
