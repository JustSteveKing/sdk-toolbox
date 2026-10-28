<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Integrity;

/**
 * The algorithms RFC 9530 registers as Active (section 5). The deprecated
 * ones (md5, sha, unixsum, unixcksum, adler, crc32c) MUST NOT be used
 * where an attacker might be involved, so they aren't offered at all.
 */
enum DigestAlgorithm: string
{
    case Sha256 = 'sha-256';
    case Sha512 = 'sha-512';

    public function hash(string $content): string
    {
        return hash($this === self::Sha256 ? 'sha256' : 'sha512', $content, true);
    }
}
