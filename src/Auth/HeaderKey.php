<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * An API key in a header of the API's choosing, such as `X-Api-Key`. The
 * header is masked in exceptions and logs whatever it's called.
 */
final readonly class HeaderKey implements Authenticator
{
    public function __construct(
        private string $header,
        #[SensitiveParameter]
        private string $key,
    ) {}

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader($this->header, $this->key);
    }

    public function sensitive(): array
    {
        return ['headers' => [$this->header], 'query' => []];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['header' => $this->header, 'key' => '[redacted]'];
    }
}
