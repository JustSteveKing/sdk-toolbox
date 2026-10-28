<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use JustSteveKing\SdkToolbox\Auth\Refreshable;

/**
 * Sends whatever token a TokenProvider hands out, as a Bearer token, and
 * renews it once when the API rejects it (RFC 6750 section 3.1): the
 * authenticator for a grant you fetch yourself. See TokenProvider.
 */
final readonly class ProvidedToken implements Refreshable
{
    use BearerFromProvider;

    public function __construct(
        private TokenProvider $provider,
    ) {}
}
