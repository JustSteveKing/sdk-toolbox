<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * A started authorisation: where to send the user, and the two secrets to
 * keep (in the session) until they come back.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $state,
        public Pkce $pkce,
    ) {}
}
