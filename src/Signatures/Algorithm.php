<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

/**
 * RFC 9421 signature algorithms (section 3.3), the two that need nothing
 * beyond PHP's own hash and sodium extensions.
 */
enum Algorithm: string
{
    /**
     * HMAC with SHA-256 (section 3.3.3). Symmetric: anyone who can verify
     * can also sign, so see section 7.3.3 before choosing it.
     */
    case HmacSha256 = 'hmac-sha256';

    /**
     * EdDSA over Curve25519 (section 3.3.6).
     */
    case Ed25519 = 'ed25519';
}
