<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Log\LogLevel;

/**
 * The retry policy decided to try again, after `delay` seconds.
 */
final readonly class RetryScheduled implements Event
{
    /**
     * @param  int|null  $status  the status that's being retried, or null after a network failure
     */
    public function __construct(
        public RequestSnapshot $request,
        public int $attempt,
        public float $delay,
        public ?int $status,
    ) {}

    public function level(): string
    {
        return LogLevel::INFO;
    }

    public function message(): string
    {
        $after = $this->status === null ? 'a network failure' : (string) $this->status;

        return sprintf('Retrying %s in %.2fs after %s (attempt %d)', $this->request, $this->delay, $after, $this->attempt + 1);
    }

    public function context(): array
    {
        return [
            'method' => $this->request->method,
            'uri' => $this->request->uri,
            'attempt' => $this->attempt,
            'delay' => $this->delay,
            'status' => $this->status,
        ];
    }
}
