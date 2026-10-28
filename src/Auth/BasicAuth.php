<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * HTTP Basic (RFC 7617).
 */
final readonly class BasicAuth implements Authenticator
{
    public function __construct(
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {
        if (str_contains($username, ':')) {
            throw new InvalidRequestException('A Basic auth user-id can\'t contain a colon (RFC 7617 section 2).');
        }
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Basic '.base64_encode("{$this->username}:{$this->password}"));
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
        return ['username' => $this->username, 'password' => '[redacted]'];
    }
}
