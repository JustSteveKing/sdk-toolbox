<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Redaction;

use Stringable;

/**
 * What an exception keeps of the request that failed: enough to debug it,
 * with the credentials already masked.
 */
final readonly class RequestSnapshot implements Stringable
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers,
    ) {}

    public function __toString(): string
    {
        return "{$this->method} {$this->uri}";
    }
}
