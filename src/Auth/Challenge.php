<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

/**
 * One challenge from a `WWW-Authenticate` header (RFC 9110 section 11.6.1).
 */
final readonly class Challenge
{
    /**
     * @param  string  $scheme  lowercased
     * @param  array<string, string>  $parameters  names lowercased
     */
    public function __construct(
        public string $scheme,
        public array $parameters = [],
        public ?string $token68 = null,
    ) {}

    public function parameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }
}
