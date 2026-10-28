<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Middleware;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One step between the connector and the HTTP client.
 *
 * A middleware can change the request before passing it on, change the
 * response on the way back, call `$next` more than once (a retry), or not at
 * all (a cache hit). Everything is PSR-7, so requests are immutable: keep
 * what `withHeader()` returns.
 */
interface Middleware
{
    /**
     * @throws ClientExceptionInterface when no response came back
     */
    public function handle(RequestInterface $request, Next $next): ResponseInterface;
}
