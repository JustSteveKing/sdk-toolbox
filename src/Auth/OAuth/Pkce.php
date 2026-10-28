<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only.
 *
 * The verifier is 32 random bytes, base64url-encoded to 43 characters:
 * the 256 bits of entropy section 7.1 asks for, at the RFC's minimum
 * length. `plain` isn't offered at all, since a client that can do S256
 * MUST (section 4.2) and RFC 9700 says the same.
 */
final readonly class Pkce
{
    public function __construct(
        public string $verifier,
    ) {}

    public static function generate(): self
    {
        return new self(self::base64url(random_bytes(32)));
    }

    public function challenge(): string
    {
        return self::base64url(hash('sha256', $this->verifier, true));
    }

    public function method(): string
    {
        return 'S256';
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
