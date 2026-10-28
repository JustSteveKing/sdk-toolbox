<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use SensitiveParameter;

/**
 * A key to check signatures with: the shared secret for HMAC, the public
 * key for Ed25519.
 */
final readonly class VerificationKey
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
     * @param  string  $key  32 raw bytes, or an SPKI PEM
     */
    public static function ed25519(string $id, string $key): self
    {
        return new self($id, Algorithm::Ed25519, Ed25519::publicKey($key));
    }

    public function verify(string $base, string $signature): bool
    {
        return match ($this->algorithm) {
            Algorithm::HmacSha256 => hash_equals(hash_hmac('sha256', $base, $this->material, true), $signature),
            Algorithm::Ed25519 => strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $base, $this->material),
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
