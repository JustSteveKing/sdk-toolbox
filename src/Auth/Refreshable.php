<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * An authenticator whose credentials can be renewed, so a request the API
 * rejected with 401 `invalid_token` can be tried once more with new ones
 * (RFC 6750 section 3.1).
 */
interface Refreshable extends Authenticator
{
    /**
     * @param  RequestInterface  $rejected  the request the API turned away, carrying the stale credentials
     */
    public function refresh(RequestInterface $rejected): void;
}
