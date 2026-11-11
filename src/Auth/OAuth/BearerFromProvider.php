<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * @internal
 */
trait BearerFromProvider
{
    public function authenticate(#[SensitiveParameter] RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', "Bearer {$this->provider->current()->token}");
    }

    public function refresh(#[SensitiveParameter] RequestInterface $rejected): void
    {
        $header = $rejected->getHeaderLine('Authorization');

        $this->provider->refresh(str_starts_with($header, 'Bearer ') ? substr($header, 7) : null);
    }

    public function sensitive(): array
    {
        return ['headers' => [], 'query' => []];
    }
}
