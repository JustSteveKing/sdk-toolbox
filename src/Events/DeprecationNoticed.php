<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Deprecation\DeprecationNotice;
use Psr\Log\LogLevel;

/**
 * The API said an endpoint is deprecated or being sunset. Once per
 * endpoint per process.
 */
final readonly class DeprecationNoticed implements Event
{
    public function __construct(
        public DeprecationNotice $notice,
    ) {}

    public function level(): string
    {
        return LogLevel::WARNING;
    }

    public function message(): string
    {
        return $this->notice->describe();
    }

    public function context(): array
    {
        return [
            'method' => $this->notice->method,
            'uri' => $this->notice->uri,
            'deprecated_at' => $this->notice->deprecatedAt?->format(DATE_ATOM),
            'sunset_at' => $this->notice->sunsetAt?->format(DATE_ATOM),
            'link' => $this->notice->deprecationLink,
        ];
    }
}
