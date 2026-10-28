<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Log\LogLevel;

/**
 * An attempt is about to go to the HTTP client. A retried request sends
 * one of these per attempt; a cache hit sends none.
 */
final readonly class RequestSending implements Event
{
    public function __construct(
        public RequestSnapshot $request,
        public int $attempt,
    ) {}

    public function level(): string
    {
        return LogLevel::DEBUG;
    }

    public function message(): string
    {
        return $this->attempt > 1 ? "Sending {$this->request} (attempt {$this->attempt})" : "Sending {$this->request}";
    }

    public function context(): array
    {
        return ['method' => $this->request->method, 'uri' => $this->request->uri, 'attempt' => $this->attempt];
    }
}
