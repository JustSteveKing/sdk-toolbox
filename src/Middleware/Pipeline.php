<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Middleware;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Middleware in front of a PSR-18 client, and itself a PSR-18 client.
 *
 * The first middleware sees the request first and the response last. The
 * client underneath can be anything PSR-18, an HTTPlug PluginClient
 * included, so HTTPlug's plugins still work beneath the toolbox's own.
 */
final readonly class Pipeline implements ClientInterface
{
    /**
     * @param  list<Middleware>  $middleware
     */
    public function __construct(
        private ClientInterface $client,
        private array $middleware = [],
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $next = new Next($this->client->sendRequest(...));

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = new Next(static fn (RequestInterface $request): ResponseInterface => $middleware->handle($request, $next));
        }

        return $next->handle($request);
    }
}
