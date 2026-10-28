<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * Turns the ways an Ed25519 key arrives (raw, base64, PEM) into the raw
 * bytes sodium takes.
 *
 * @internal
 */
final class Ed25519
{
    /**
     * A 64-byte sodium secret key from a 32-byte seed, a 64-byte secret, or
     * a PKCS #8 PEM private key.
     *
     * @return non-empty-string
     */
    public static function secretKey(string $key): string
    {
        $raw = self::raw($key, 'PRIVATE KEY');

        /** @var non-empty-string $raw its length is checked below */
        return match (strlen($raw)) {
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => $raw,
            SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw)),
            default => throw new InvalidRequestException('An Ed25519 private key is a 32-byte seed, a 64-byte secret key or a PKCS #8 PEM.'),
        };
    }

    /**
     * A 32-byte public key from raw bytes or an SPKI PEM public key.
     *
     * @return non-empty-string
     */
    public static function publicKey(string $key): string
    {
        $raw = self::raw($key, 'PUBLIC KEY');

        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidRequestException('An Ed25519 public key is 32 bytes, or an SPKI PEM.');
        }

        return $raw;
    }

    private static function raw(string $key, string $label): string
    {
        if (! str_contains($key, "-----BEGIN {$label}-----")) {
            return $key;
        }

        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $key) ?? '', true);

        if ($der === false || strlen($der) < 32) {
            throw new InvalidRequestException("Not a valid {$label} PEM.");
        }

        // PKCS #8 and SPKI wrap an Ed25519 key in a fixed prefix (RFC 8410
        // section 7): the key is the last 32 bytes.
        return substr($der, -32);
    }
}
