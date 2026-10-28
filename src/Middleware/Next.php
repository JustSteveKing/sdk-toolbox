<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Middleware;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The rest of the pipeline, from where a middleware stands.
 */
final readonly class Next
{
    /**
     * @param  Closure(RequestInterface): ResponseInterface  $next
     */
    public function __construct(
        private Closure $next,
    ) {}

    /**
     * @throws ClientExceptionInterface
     */
    public function handle(RequestInterface $request): ResponseInterface
    {
        return ($this->next)($request);
    }
}
