<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * How a confidential client proves who it is to the token endpoint.
 */
enum ClientAuthentication
{
    /**
     * HTTP Basic, which every server MUST support (RFC 6749 section 2.3.1).
     */
    case Basic;

    /**
     * `client_id` and `client_secret` in the form body, which the RFC says
     * is NOT RECOMMENDED but some servers require.
     */
    case Post;
}
