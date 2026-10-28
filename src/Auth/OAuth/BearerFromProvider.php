<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Psr\Http\Message\RequestInterface;

/**
 * @internal
 */
trait BearerFromProvider
{
    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', "Bearer {$this->provider->current()->token}");
    }

    public function refresh(RequestInterface $rejected): void
    {
        $header = $rejected->getHeaderLine('Authorization');

        $this->provider->refresh(str_starts_with($header, 'Bearer ') ? substr($header, 7) : null);
    }

    public function sensitive(): array
    {
        return ['headers' => [], 'query' => []];
    }
}
