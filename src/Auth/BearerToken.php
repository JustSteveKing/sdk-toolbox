<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * `Authorization: Bearer <token>` (RFC 6750 section 2.1), the only one of
 * the RFC's three ways to send a token that keeps it out of URLs and bodies.
 */
final readonly class BearerToken implements Authenticator
{
    public function __construct(
        #[SensitiveParameter]
        private string $token,
    ) {}

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function sensitive(): array
    {
        return ['headers' => [], 'query' => []];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }
}
