<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

enum RateLimitSource
{
    /**
     * `RateLimit` and `RateLimit-Policy` from
     * draft-ietf-httpapi-ratelimit-headers-11. Experimental: the draft
     * expires on 2026-11-24.
     */
    case Ietf;

    /**
     * `X-RateLimit-*` or `RateLimit-Limit/Remaining/Reset`: the conventions
     * that came before the draft, GitHub's among them.
     */
    case Legacy;
}
