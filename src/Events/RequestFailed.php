<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Log\LogLevel;

/**
 * An attempt got no response at all.
 */
final readonly class RequestFailed implements Event
{
    /**
     * @param  string  $reason  the client's message, already redacted
     * @param  class-string  $exception
     */
    public function __construct(
        public RequestSnapshot $request,
        public string $reason,
        public string $exception,
        public float $duration,
        public int $attempt,
    ) {}

    public function level(): string
    {
        return LogLevel::WARNING;
    }

    public function message(): string
    {
        return "{$this->request} failed: {$this->reason}";
    }

    public function context(): array
    {
        return [
            'method' => $this->request->method,
            'uri' => $this->request->uri,
            'exception' => $this->exception,
            'duration_ms' => (int) round($this->duration * 1000),
            'attempt' => $this->attempt,
        ];
    }
}
