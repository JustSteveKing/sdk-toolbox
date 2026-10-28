<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use SensitiveParameter;

/**
 * A key to sign messages with, and the `keyid` the other side knows it by.
 */
final readonly class SigningKey
{
    /**
     * @param  non-empty-string  $material
     */
    private function __construct(
        public string $id,
        public Algorithm $algorithm,
        #[SensitiveParameter]
        private string $material,
    ) {}

    public static function hmac(string $id, #[SensitiveParameter] string $secret): self
    {
        if ($secret === '') {
            throw new InvalidRequestException('An HMAC key needs a secret.');
        }

        return new self($id, Algorithm::HmacSha256, $secret);
    }

    /**
     * @param  string  $key  a 32-byte seed, a 64-byte secret key, or a PKCS #8 PEM
     */
    public static function ed25519(string $id, #[SensitiveParameter] string $key): self
    {
        return new self($id, Algorithm::Ed25519, Ed25519::secretKey($key));
    }

    public function sign(string $base): string
    {
        return match ($this->algorithm) {
            Algorithm::HmacSha256 => hash_hmac('sha256', $base, $this->material, true),
            Algorithm::Ed25519 => sodium_crypto_sign_detached($base, $this->material),
        };
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'algorithm' => $this->algorithm->value, 'material' => '[redacted]'];
    }
}
