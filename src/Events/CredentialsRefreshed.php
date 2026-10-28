<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Log\LogLevel;

/**
 * The API rejected the credentials, they were renewed, and the request is
 * going again once.
 */
final readonly class CredentialsRefreshed implements Event
{
    public function __construct(
        public RequestSnapshot $request,
    ) {}

    public function level(): string
    {
        return LogLevel::INFO;
    }

    public function message(): string
    {
        return "Credentials rejected for {$this->request}; renewed and retrying once";
    }

    public function context(): array
    {
        return ['method' => $this->request->method, 'uri' => $this->request->uri];
    }
}
